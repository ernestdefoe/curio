<?php

namespace Ernestdefoe\Curio\Api;

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
use Flarum\Api\Schema;
use Flarum\Api\Sort\SortColumn;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\Post\Exception\FloodingException;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Psr\Http\Message\UploadedFileInterface;
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

    public function __construct(
        private Images $images,
        private Titles $titles,
        private CollectionStats $stats,
        private BarcodeLookup $barcodes,
        private WorkTexts $texts,
        private TranslatorInterface $translator,
        private Cache $cache
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
                ->eagerLoad(['work'])
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

        if (! $collection || $collection->user_id !== $actor->id) {
            throw new PermissionDeniedException();
        }

        if (Item::query()->where('collection_id', $collection->id)->count() >= Collection::MAX_ITEMS) {
            throw new ValidationException(['title' => $this->translator->trans('ernestdefoe-curio.api.collection_full', ['count' => Collection::MAX_ITEMS])]);
        }

        $model->user_id = $actor->id;
        $model->status ??= 'owned';
        $model->favourite ??= false;
        $model->position = (int) Item::query()->where('collection_id', $collection->id)->max('position') + 1;

        return $model;
    }

    public function saving(object $model, OriginalContext $context): ?object
    {
        /** @var Item $model */
        if (array_key_exists(spl_object_id($model), $this->pendingFields)) {
            $type = $model->collection?->type;
            $model->fields = $type ? self::cleanFields($type, $this->pendingFields[spl_object_id($model)]) : [];
            $facet = $type?->facetField();
            $model->facet = $facet ? ($model->fields[$facet['key']] ?? null) : null;
            unset($this->pendingFields[spl_object_id($model)]);
        }

        return $model;
    }

    public function saved(object $model, OriginalContext $context): ?object
    {
        /** @var Item $model */
        if ($model->wasRecentlyCreated && $model->barcode) {
            $this->barcodes->remember($model->barcode, $model->work_id, $model->title, $model->fields ?? [], $model->work_id ? (string) $model->work?->source : 'member');
        }

        if ($model->collection) {
            $this->stats->refresh($model->collection);
        }

        return $model;
    }

    public function deleted(object $model, OriginalContext $context): void
    {
        if (! $model instanceof Item) {
            return;
        }

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

        return [
            Schema\Str::make('title')
                ->writable()
                ->requiredOnCreate()
                ->rule('string')
                ->rule('max:255')
                ->rule('regex:/\S/'),
            // The title in the viewer's language when it came from a look-up and was left as it was.
            Schema\Str::make('displayTitle')
                ->get(fn (Item $item) => $item->work_id && $item->work && $item->title === $item->work->title
                    ? ($this->titles->get($item->work_id) ?? $item->title)
                    : $item->title),
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
            Schema\Str::make('condition')
                ->nullable()
                ->writable()
                ->in(Item::CONDITIONS),
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
                ->writable()
                ->rule('numeric')
                ->rule('between:0,99999999')
                ->get(fn (Item $item) => $item->price_paid !== null ? (float) $item->price_paid : null),
            Schema\Str::make('priceCurrency')
                ->nullable()
                ->visible($isOwner)
                ->writable()
                ->rule('regex:/^[A-Z]{3}$/'),
            Schema\Integer::make('position'),
            Schema\Arr::make('photos')
                ->get(fn (Item $item) => array_map(fn ($p) => ['url' => $this->images->url($p), 'small' => $this->images->url($p, true)], $item->photos ?? [])),
            Schema\Str::make('coverUrl')
                ->nullable()
                ->get(fn (Item $item) => $this->images->url($item->coverPath(), true)),
            Schema\DateTime::make('createdAt'),
            Schema\Boolean::make('canEdit')
                ->get(fn (Item $item, Context $context) => $context->getActor()->can('update', $item)),

            Schema\Relationship\ToOne::make('collection')
                ->type('curio-collections')
                ->includable()
                ->writableOnCreate()
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

    public function sorts(): array
    {
        return [
            SortColumn::make('position'),
            SortColumn::make('createdAt'),
            SortColumn::make('title'),
            SortColumn::make('year'),
        ];
    }

    /**
     * Only the type's own details, each in its kind.
     *
     * @return array<string, mixed>
     */
    public static function cleanFields(Type $type, mixed $value): array
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
                'select' => in_array($v, array_column($field['options'] ?? [], 'key'), true) ? $v : null,
                default => is_scalar($v) ? mb_substr(trim((string) $v), 0, 200) : null,
            };

            if ($v !== null && $v !== '') {
                $clean[$field['key']] = $v;
            }
        }

        return $clean;
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
     * A language installed after the work was looked up has no text yet: fetch
     * it the first time someone opens the item in it (once a day at most).
     */
    private function ensureTexts(Item $item): void
    {
        $work = $item->work;
        $locale = WorkTexts::base($this->translator->getLocale());

        if (! $work?->wikidata_id || $work->texts()->where('locale', $locale)->exists()) {
            return;
        }

        if ($this->cache->add("curio.texts.$work->id.$locale", true, 86400)) {
            $this->texts->fill($work, null, null, [$locale]);
        }
    }
}
