<?php

namespace Ernestdefoe\Curio\Api;

use Carbon\Carbon;
use Ernestdefoe\Curio\Collection;
use Ernestdefoe\Curio\CollectionStats;
use Ernestdefoe\Curio\Item;
use Ernestdefoe\Curio\Lookup\Barcode;
use Ernestdefoe\Curio\Lookup\BarcodeLookup;
use Ernestdefoe\Curio\Storage\Images;
use Ernestdefoe\Curio\Storage\PhotoValidator;
use Ernestdefoe\Curio\Translation\WorkTexts;
use Ernestdefoe\Curio\Type;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Schema;
use Flarum\Api\Sort\SortColumn;
use Flarum\Discussion\Discussion;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\Post\Exception\FloodingException;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\Queue;
use Ernestdefoe\Curio\Job\NotifyFollowers;
use Ernestdefoe\Curio\Job\NotifyWishlists;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use Tobyz\JsonApiServer\Context as OriginalContext;

/**
 * Items in collections. Photos are uploaded one at a time to their own
 * endpoint; the price paid is only ever sent to the owner.
 *
 * @extends AbstractDatabaseResource<Item>
 */
class ItemResource extends AbstractDatabaseResource
{
    /** @var array<int, mixed> raw field values waiting for the item's type */
    private array $pendingFields = [];

    /** @var array<int, int> the collection an item was moved out of, by item object */
    private array $movedFrom = [];

    public function __construct(
        private Images $images,
        private Titles $titles,
        private CollectionStats $stats,
        private BarcodeLookup $barcodes,
        private WorkTexts $texts,
        private TranslatorInterface $translator,
        private Cache $cache,
        private Queue $queue,
        private LoggerInterface $log
    ) {
    }

    public function type(): string
    {
        return 'curio-items';
    }

    public function model(): string
    {
        return Item::class;
    }

    public function scope(Builder $query, OriginalContext $context): void
    {
        $query->whereVisibleTo($context->getActor());
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->paginate(48, 96)
                ->defaultSort('position')
                ->eagerLoadWhenIncluded(['collection.user' => ['collection.user.groups'], 'user' => ['user.groups']])
                ->beforeSerialization(function (Context $context, array $results) {
                    $ids = [];
                    foreach ($results['models'] as $item) {
                        $ids[] = $item->work_id;
                    }
                    $this->titles->prime($ids);
                }),
            Endpoint\Show::make()
                ->defaultInclude(['work', 'collection', 'collection.user', 'collection.type'])
                ->eagerLoad(['collection.user.groups'])
                ->beforeSerialization(fn (Context $context, Item $item) => $this->ensureTexts($item)),
            Endpoint\Create::make()
                ->authenticated()
                ->can('ernestdefoe-curio.create')
                ->defaultInclude(['work']),
            Endpoint\Update::make()
                ->authenticated()
                ->can('update')
                ->defaultInclude(['work']),
            Endpoint\Delete::make()
                ->authenticated()
                ->can('delete'),
            // Multipart: one "photo".
            Endpoint\Endpoint::make('curio.items.photos.add')
                ->route('POST', '/{id}/photos')
                ->authenticated()
                ->can('update')
                ->action(fn (Context $context) => $this->addPhoto($context->model, Arr::get($context->request->getUploadedFiles(), 'photo'), $context->getActor())),
            Endpoint\Endpoint::make('curio.items.photos.remove')
                ->route('DELETE', '/{id}/photos/{index}')
                ->authenticated()
                ->can('update')
                ->action(fn (Context $context) => $this->removePhoto($context->model, (int) ($context->request->getAttribute('routeParameters')['index'] ?? -1))),
            Endpoint\Endpoint::make('curio.items.photos.first')
                ->route('POST', '/{id}/photos/{index}/first')
                ->authenticated()
                ->can('update')
                ->action(fn (Context $context) => $this->photoFirst($context->model, (int) ($context->request->getAttribute('routeParameters')['index'] ?? -1))),
        ];
    }

    public function creating(object $model, OriginalContext $context): ?object
    {
        /** @var Item $model */
        $actor = $context->getActor();
        $collection = $model->collection;

        // The owner, or a moderator helping out: the item is still the owner's.
        if (! $collection || $actor->cannot('addItems', $collection)) {
            throw new PermissionDeniedException();
        }

        if (Item::query()->where('collection_id', $collection->id)->count() >= Collection::MAX_ITEMS) {
            throw new ValidationException(['title' => $this->translator->trans('ernestdefoe-curio.api.collection_full', ['count' => Collection::MAX_ITEMS])]);
        }

        $model->user_id = $collection->user_id;
        $model->status ??= 'owned';
        $model->favourite ??= false;
        $model->graded ??= false;
        $model->position = (int) Item::query()->where('collection_id', $collection->id)->max('position') + 1;

        return $model;
    }

    public function saving(object $model, OriginalContext $context): ?object
    {
        /** @var Item $model */
        // For the Collections page's "Latest changes".
        if ($model->isDirty('progress')) {
            $model->progress_changed_at = $model->progress ? Carbon::now() : null;
        }
        if ($model->isDirty('rating')) {
            $model->rating_changed_at = $model->rating !== null ? Carbon::now() : null;
        }

        if ($model->exists && $model->isDirty('collection_id')) {
            $this->move($model, $context->getActor());
        }

        // Each type keeps its own condition scale: cards are Near Mint, records Very good.
        if ($model->isDirty('condition') && $model->condition !== null && ! in_array($model->condition, $model->collection?->type?->conditionList() ?? Item::CONDITIONS, true)) {
            throw new ValidationException(['condition' => $this->translator->trans('validation.in', ['attribute' => 'condition'])]);
        }

        if (array_key_exists(spl_object_id($model), $this->pendingFields)) {
            $type = $model->collection?->type;
            $invalid = [];
            $model->fields = $type ? self::cleanFields($type, $this->pendingFields[spl_object_id($model)], $invalid) : [];
            if ($invalid) {
                throw new ValidationException(['fields' => $this->translator->trans('ernestdefoe-curio.api.bad_link')]);
            }
            $facet = $type?->facetField();
            $model->facet = $facet ? ($model->fields[$facet['key']] ?? null) : null;
            unset($this->pendingFields[spl_object_id($model)]);
        }

        return $model;
    }

    public function saved(object $model, OriginalContext $context): ?object
    {
        /** @var Item $model */
        $this->audit($context->getActor(), $model, $model->wasRecentlyCreated ? 'added' : 'changed');

        if ($model->wasRecentlyCreated && $model->barcode) {
            $this->barcodes->remember($model->barcode, $model->work_id, $model->title, $model->fields ?? [], $model->work_id ? (string) $model->work?->source : 'member');
        }

        if ($model->collection) {
            $this->stats->refresh($model->collection);
        }
        if ($from = Collection::query()->find($this->movedFrom[spl_object_id($model)] ?? null)) {
            $this->stats->refresh($from);
        }
        unset($this->movedFrom[spl_object_id($model)]);

        // Newly up for trade: the members who want it hear about it.
        if ($model->status === 'trade' && $model->work_id && ($model->wasRecentlyCreated || $model->wasChanged('status') || $model->wasChanged('work_id'))) {
            $this->queue->push(new NotifyWishlists($model->id));
        }

        // Followers hear about it, once a day per kind: something new on the
        // shelf or the wishlist, something started or finished, a new rating.
        // (Imports and look-up matching save items directly, never through here.)
        $kinds = [];
        if ($model->wasRecentlyCreated || $model->wasChanged('status')) {
            $kinds[] = $model->status === 'wishlist' ? 'wishlist' : ($model->wasRecentlyCreated ? 'new' : null);
        }
        if (! $model->wasRecentlyCreated && $model->wasChanged('progress') && $model->progress) {
            $kinds[] = 'progress';
        }
        if (! $model->wasRecentlyCreated && $model->wasChanged('rating') && $model->rating !== null) {
            $kinds[] = 'rating';
        }
        foreach (array_filter($kinds) as $kind) {
            $this->queue->push(new NotifyFollowers($model->collection_id, $kind, $kind === 'new' ? null : $model->id));
        }

        return $model;
    }

    public function deleted(object $model, OriginalContext $context): void
    {
        if (! $model instanceof Item) {
            return;
        }

        $this->audit($context->getActor(), $model, 'removed');

        foreach ($model->photos ?? [] as $photo) {
            $this->images->delete($photo);
        }

        if ($collection = Collection::query()->find($model->collection_id)) {
            $this->stats->refresh($collection);
        }
    }

    public function fields(): array
    {
        $isOwner = fn (Item $item, Context $context) => ! $context->getActor()->isGuest() && $item->user_id === $context->getActor()->id;
        // What the owner paid stays theirs: a moderator editing the item neither sees nor changes it.
        $ownerWrites = fn (Item $item, Context $context) => ! $item->exists || $isOwner($item, $context);

        return [
            Schema\Str::make('title')
                ->writable()
                ->requiredOnCreate()
                ->rule('string')
                ->rule('max:255')
                ->rule('regex:/\S/'),
            // The title in the viewer's language when it came from a look-up and was left as it was.
            Schema\Str::make('displayTitle')
                ->get(function (Item $item) {
                    $this->titles->queue($item->work_id);

                    return function () use ($item) {
                        $work = $item->work_id ? $this->titles->work($item->work_id) : null;

                        return $work && $item->title === $work['title'] ? ($work['local'] ?? $item->title) : $item->title;
                    };
                }),
            Schema\Str::make('subtitle')
                ->nullable()
                ->writable()
                ->rule('max:255'),
            Schema\Integer::make('year')
                ->nullable()
                ->writable()
                ->rule('integer')
                ->rule('between:1,2999'),
            Schema\Str::make('status')
                ->writable()
                ->in(Item::STATUSES),
            // On the scale of the item's type (checked when saved).
            Schema\Str::make('condition')
                ->nullable()
                ->writable()
                ->in(array_values(array_unique([...Item::CONDITIONS, ...Item::CARD_CONDITIONS]))),
            // Professionally graded (slabbed); the member's photos show by whom and the score.
            Schema\Boolean::make('graded')
                ->writable(),
            Schema\Date::make('acquiredOn')
                ->nullable()
                ->writable()
                ->rule('before_or_equal:today'),
            Schema\Arr::make('fields')
                ->writable()
                ->get(fn (Item $item) => (object) ($item->fields ?? []))
                ->set(fn (Item $item, $value) => $this->pendingFields[spl_object_id($item)] = $value),
            Schema\Str::make('facet'),
            Schema\Str::make('notes')
                ->nullable()
                ->writable()
                ->rule('max:2000'),
            Schema\Boolean::make('favourite')
                ->writable(),
            // The owner's rating in half stars, 0 (no stars at all) to 10 (★★★★★); null when unrated.
            Schema\Integer::make('rating')
                ->nullable()
                ->writable()
                ->rule('integer')
                ->rule('between:0,10'),
            // Not started, in progress, completed or abandoned; shown where the item's type tracks it.
            Schema\Str::make('progress')
                ->nullable()
                ->writable()
                ->in(Item::PROGRESS),
            Schema\Str::make('barcode')
                ->nullable()
                ->visible($isOwner)
                ->writableOnCreate()
                ->set(function (Item $item, $value) {
                    $item->barcode = $value ? Barcode::normalise((string) $value) : null;
                }),
            Schema\Number::make('pricePaid')
                ->nullable()
                ->visible($isOwner)
                ->writable($ownerWrites)
                ->rule('numeric')
                ->rule('between:0,99999999')
                ->get(fn (Item $item) => $item->price_paid !== null ? (float) $item->price_paid : null),
            Schema\Str::make('priceCurrency')
                ->nullable()
                ->visible($isOwner)
                ->writable($ownerWrites)
                ->rule('regex:/^[A-Z]{3}$/'),
            Schema\Integer::make('position'),
            Schema\Arr::make('photos')
                ->get(fn (Item $item) => array_map(fn ($p) => ['url' => $this->images->url($p), 'small' => $this->images->url($p, true)], $item->photos ?? [])),
            Schema\Str::make('coverUrl')
                ->nullable()
                ->get(function (Item $item) {
                    if ($item->photos) {
                        return $this->images->url($item->photos[0], true);
                    }
                    $this->titles->queue($item->work_id);

                    return fn () => $item->work_id ? $this->images->url($this->titles->work($item->work_id)['cover'] ?? null, true) : null;
                }),
            Schema\DateTime::make('createdAt'),
            Schema\DateTime::make('progressChangedAt')->nullable(),
            Schema\DateTime::make('ratingChangedAt')->nullable(),
            // The discussion it was shown off in, on the item's own page, when the reader can see it.
            Schema\Integer::make('discussionId')
                ->nullable()
                ->visible(fn (Item $item, Context $context) => $item->discussion_id && self::alone($context))
                ->get(fn (Item $item, Context $context) => Discussion::query()->whereVisibleTo($context->getActor())->whereKey($item->discussion_id)->exists() ? $item->discussion_id : null),
            // Its title as Marquee knows it, and the discussion that title already has (Show it off).
            Schema\Arr::make('marquee')
                ->nullable()
                ->visible(fn (Item $item, Context $context) => $item->work_id && $context->showing(self::class))
                ->get(fn (Item $item, Context $context) => MarqueeTitle::for($item, $context->getActor())),
            Schema\Integer::make('collectionId')->property('collection_id'),
            // Which looked-up title it is: "Who has this" and grouping search results.
            Schema\Integer::make('workId')->property('work_id')->nullable(),
            // On a wanted item's own page: how many others have it open to trade, and the first such offer.
            Schema\Arr::make('tradeOffers')
                ->nullable()
                ->visible(fn (Item $item, Context $context) => $item->work_id && $item->status === 'wishlist' && self::alone($context))
                ->get(function (Item $item, Context $context) {
                    $offers = Item::query()->whereVisibleTo($context->getActor())
                        ->where('work_id', $item->work_id)->where('status', 'trade')->where('user_id', '!=', $item->user_id);

                    $first = (clone $offers)->orderBy('id')->first(['id', 'collection_id']);

                    return ['count' => (clone $offers)->count(), 'itemId' => $first?->id, 'collectionId' => $first?->collection_id];
                }),
            // How many members have it (owned or to trade), on the item's own page.
            Schema\Integer::make('ownersCount')
                ->visible(fn (Item $item, Context $context) => $item->work_id && self::alone($context))
                ->get(fn (Item $item, Context $context) => (int) Item::query()->whereVisibleTo($context->getActor())
                    ->where('work_id', $item->work_id)->where('status', '!=', 'wishlist')->distinct()->count('user_id')),
            Schema\Boolean::make('canEdit')
                ->get(fn (Item $item, Context $context) => $context->getActor()->can('update', $item)),

            // Afterwards, moving it to another of the owner's collections (checked in move()).
            Schema\Relationship\ToOne::make('collection')
                ->type('curio-collections')
                ->includable()
                ->writable()
                ->requiredOnCreate(),
            Schema\Relationship\ToOne::make('work')
                ->type('curio-works')
                ->includable()
                ->nullable()
                ->writable(),
            Schema\Relationship\ToOne::make('user')
                ->type('users')
                ->includable(),
        ];
    }

    /** Shown one at a time: the item's own page, or the hero of the discussion it is shown off in. */
    private static function alone(Context $context): bool
    {
        return $context->showing(self::class) || $context->showing(DiscussionResource::class);
    }

    public function sorts(): array
    {
        return [
            SortColumn::make('position'),
            SortColumn::make('createdAt'),
            SortColumn::make('title'),
            SortColumn::make('year'),
            SortColumn::make('rating'),
            SortColumn::make('progressChangedAt'),
            SortColumn::make('ratingChangedAt'),
        ];
    }

    /**
     * Only the type's own details, each in its kind. A link that is not a
     * plain http(s) address is left out, and named in $invalid.
     *
     * @param list<string> $invalid
     * @return array<string, mixed>
     */
    public static function cleanFields(Type $type, mixed $value, array &$invalid = []): array
    {
        $clean = [];
        $value = (array) $value;

        foreach ($type->fieldList() as $field) {
            $v = $value[$field['key']] ?? null;

            if ($v === null || $v === '') {
                continue;
            }

            $v = match ($field['kind']) {
                'bool' => (bool) $v,
                'number' => is_numeric($v) ? $v + 0 : null,
                'date' => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null,
                'select' => Type::allows($field, $v) ? $v : null,
                'link' => self::url($v),
                default => is_scalar($v) ? mb_substr(trim((string) $v), 0, 200) : null,
            };

            if ($v !== null && $v !== '') {
                $clean[$field['key']] = $v;
            } elseif ($field['kind'] === 'link') {
                $invalid[] = $field['key'];
            }
        }

        return $clean;
    }

    /**
     * A web address members may link to: http or https, a host, no
     * credentials, no spaces or control characters, at most 500 characters.
     * "www.example.com/review" gets https:// in front.
     */
    public static function url(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $url = trim($value);
        if (! preg_match('~^[a-z][a-z0-9+.-]*:~i', $url) && preg_match('~^[\p{L}\p{N}-]+(\.[\p{L}\p{N}-]+)+([/?#]|$)~u', $url)) {
            $url = 'https://'.$url;
        }

        if ($url === '' || mb_strlen($url) > 500 || preg_match('/[\s\x00-\x1f\x7f<>"`\\\\]/u', $url)) {
            return null;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && preg_match('/^[\p{L}\p{N}.-]+$/u', $parts['host'] ?? '')
            && str_contains($parts['host'], '.')
            && ! isset($parts['user'])
            ? $url : null;
    }

    private function addPhoto(Item $item, ?UploadedFileInterface $file, User $actor): Item
    {
        // Each photo is decoded and encoded twice: members get a dozen a minute and 200 a day.
        if (! $actor->isAdmin() && (! $this->spend("curio.photos.$actor->id.minute", 12, 60)
            || ! $this->spend("curio.photos.$actor->id.".date('Ymd'), 200, 2 * 86400))) {
            throw new FloodingException();
        }

        if (count($item->photos ?? []) >= Item::MAX_PHOTOS) {
            throw new ValidationException(['photo' => $this->translator->trans('ernestdefoe-curio.api.too_many_photos', ['count' => Item::MAX_PHOTOS])]);
        }

        if (! $file) {
            throw new ValidationException(['photo' => $this->translator->trans('validation.required', ['attribute' => 'photo'])]);
        }

        resolve(PhotoValidator::class)->assertImageValid('photo', $file);

        $item->photos = [...($item->photos ?? []), $this->images->store((string) $file->getStream()->getMetadata('uri'), 'photos')];
        $item->save();
        $this->refreshCollection($item);

        return $item;
    }

    /**
     * A moderator's change to someone else's item goes in the forum's log,
     * so there is a trail of who touched what.
     */
    /**
     * Into another of the same member's collections, at its end. Another type
     * keeps only the details it has too, and a condition on its own scale.
     */
    private function move(Item $item, User $actor): void
    {
        $from = (int) $item->getOriginal('collection_id');
        $to = $item->collection;

        if (! $to || $to->user_id !== $item->user_id || $actor->cannot('addItems', $to)) {
            throw new PermissionDeniedException();
        }
        if (Item::query()->where('collection_id', $to->id)->count() >= Collection::MAX_ITEMS) {
            throw new ValidationException(['collection' => $this->translator->trans('ernestdefoe-curio.api.collection_full', ['count' => Collection::MAX_ITEMS])]);
        }

        $item->position = (int) Item::query()->where('collection_id', $to->id)->max('position') + 1;
        $this->movedFrom[spl_object_id($item)] = $from;

        $type = $to->type;
        if ($type && $type->id !== Collection::query()->whereKey($from)->value('type_id')) {
            $item->fields = self::cleanFields($type, $item->fields ?? []);
            $facet = $type->facetField();
            $value = $facet ? ($item->fields[$facet['key']] ?? null) : null;
            $item->facet = $facet && Type::allows($facet, $value) ? $value : null;
            if ($item->condition !== null && ! in_array($item->condition, $type->conditionList(), true)) {
                $item->condition = null;
            }
        }
    }

    private function audit(User $actor, Item $item, string $action): void
    {
        if ($actor->isGuest() || $actor->id === $item->user_id) {
            return;
        }

        $this->log->info(sprintf('[curio] %s (#%d) %s item #%d “%s” in collection #%d of user #%d', $actor->username, $actor->id, $action, $item->id, preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $item->title), $item->collection_id, $item->user_id));
    }

    /** A window that starts with the first use, so no clock boundary resets it early. */
    private function spend(string $counter, int $limit, int $ttl): bool
    {
        $this->cache->add($counter, 0, $ttl);

        return $this->cache->increment($counter) <= $limit;
    }

    private function removePhoto(Item $item, int $index): Item
    {
        $photos = $item->photos ?? [];

        if (isset($photos[$index])) {
            $this->images->delete($photos[$index]);
            array_splice($photos, $index, 1);
            $item->photos = $photos ?: null;
            $item->save();
            $this->refreshCollection($item);
        }

        return $item;
    }

    private function photoFirst(Item $item, int $index): Item
    {
        $photos = $item->photos ?? [];

        if ($index > 0 && isset($photos[$index])) {
            $chosen = array_splice($photos, $index, 1);
            $item->photos = [...$chosen, ...$photos];
            $item->save();
            $this->refreshCollection($item);
        }

        return $item;
    }

    private function refreshCollection(Item $item): void
    {
        if ($collection = Collection::query()->find($item->collection_id)) {
            $this->stats->refresh($collection);
        }
    }

    /**
     * The reader's language has no text yet (installed after the look-up), or
     * the work never matched Wikidata (busy at look-up): fetch it the first
     * time someone opens the item, once a day at most.
     */
    private function ensureTexts(Item $item): void
    {
        $work = $item->work;
        $locale = WorkTexts::base($this->translator->getLocale());

        if (! $work) {
            return;
        }

        $unmatched = ! $work->wikidata_id && $work->created_at > Carbon::now()->subDays(30);

        if (($unmatched || ! $work->texts()->where('locale', $locale)->exists()) && $this->cache->add("curio.texts.$work->id.$locale", true, 86400)) {
            $this->texts->fill($work, null, null, [$locale]);
        }
    }
}
