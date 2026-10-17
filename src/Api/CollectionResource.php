<?php

namespace Ernestdefoe\Curio\Api;

use Carbon\Carbon;
use Ernestdefoe\Curio\Collection;
use Ernestdefoe\Curio\CollectionStats;
use Ernestdefoe\Curio\Item;
use Ernestdefoe\Curio\Storage\Images;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Api\Sort\SortColumn;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Laminas\Diactoros\Response\EmptyResponse;
use Tobyz\JsonApiServer\Context as OriginalContext;

/**
 * A member's collection. Its items are reordered through the write-only
 * `order` attribute, so the list never has to be sent back whole.
 *
 * @extends AbstractDatabaseResource<Collection>
 */
class CollectionResource extends AbstractDatabaseResource
{
    /** @var array<int, list<int>> */
    private array $orders = [];

    public function __construct(private Images $images, private TranslatorInterface $translator, private ConnectionInterface $db)
    {
    }

    public function type(): string
    {
        return 'curio-collections';
    }

    public function model(): string
    {
        return Collection::class;
    }

    public function scope(Builder $query, OriginalContext $context): void
    {
        $query->whereVisibleTo($context->getActor());
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->paginate(24, 50)
                ->defaultInclude(['user', 'type'])
                ->defaultSort('position'),
            Endpoint\Show::make()
                ->defaultInclude(['user', 'type']),
            Endpoint\Create::make()
                ->authenticated()
                ->can('ernestdefoe-curio.create')
                ->defaultInclude(['user', 'type']),
            Endpoint\Update::make()
                ->authenticated()
                ->defaultInclude(['user', 'type']),
            Endpoint\Delete::make()
                ->authenticated()
                ->can('delete'),
            // The owner's new order for all their collections: {"ids": [3, 1, 2]}.
            Endpoint\Endpoint::make('curio.collections.order')
                ->route('POST', '/order')
                ->authenticated()
                ->can('ernestdefoe-curio.create')
                ->action(function (Context $context) {
                    $ids = array_map('intval', array_slice((array) ($context->body()['ids'] ?? []), 0, Collection::MAX_PER_USER));
                    foreach ($ids as $position => $id) {
                        Collection::query()->where('id', $id)->where('user_id', $context->getActor()->id)->update(['position' => $position]);
                    }

                    return [];
                })
                ->response(fn () => new EmptyResponse(204)),
        ];
    }

    public function creating(object $model, OriginalContext $context): ?object
    {
        /** @var Collection $model */
        $actor = $context->getActor();

        if (Collection::query()->where('user_id', $actor->id)->count() >= Collection::MAX_PER_USER) {
            throw new ValidationException(['name' => $this->translator->trans('ernestdefoe-curio.api.too_many_collections', ['count' => Collection::MAX_PER_USER])]);
        }

        $model->user_id = $actor->id;
        // Flarum's models do not take Eloquent's $attributes defaults, so a new row states its own.
        $model->visibility ??= 'public';
        $model->wishlist_public ??= true;
        $model->position = (int) Collection::query()->where('user_id', $actor->id)->max('position') + 1;

        return $model;
    }

    public function saving(object $model, OriginalContext $context): ?object
    {
        /** @var Collection $model */
        if ($model->isDirty('name')) {
            $model->slug = Str::slug($model->name) ?: 'collection';
        }

        return $model;
    }

    public function saved(object $model, OriginalContext $context): ?object
    {
        /** @var Collection $model */
        $order = $this->orders[spl_object_id($model)] ?? null;
        unset($this->orders[spl_object_id($model)]);

        if ($order !== null) {
            $this->db->transaction(function () use ($model, $order) {
                foreach ($order as $position => $id) {
                    Item::query()->where('collection_id', $model->id)->where('id', $id)->update(['position' => $position]);
                }
            });
            resolve(CollectionStats::class)->refresh($model);
        }

        return $model;
    }

    public function deleting(object $model, OriginalContext $context): void
    {
        /** @var Collection $model */
        // Photos go with their items; the rows go by cascade.
        Item::query()->where('collection_id', $model->id)->whereNotNull('photos')->select(['id', 'photos'])
            ->each(fn (Item $item) => array_map($this->images->delete(...), $item->photos ?? []));
    }

    public function fields(): array
    {
        // Writable while creating, and afterwards by the owner only.
        $owner = fn (Collection $c, Context $context) => ! $c->exists || $c->isOwnedBy($context->getActor());

        return [
            Schema\Str::make('name')
                ->writable($owner)
                ->requiredOnCreate()
                ->rule('string')
                ->rule('max:100')
                ->rule('regex:/\S/'),
            Schema\Str::make('slug'),
            Schema\Str::make('description')
                ->nullable()
                ->writable($owner)
                ->rule('max:1000'),
            Schema\Str::make('visibility')
                ->writable($owner)
                ->in(Collection::VISIBILITIES),
            Schema\Boolean::make('wishlistPublic')
                ->property('wishlist_public')
                ->writable($owner),
            Schema\Boolean::make('featured')
                ->get(fn (Collection $c) => $c->featured_at !== null)
                ->writable(fn (Collection $c, Context $context) => $context->getActor()->can('feature', $c))
                ->set(fn (Collection $c, $value) => $c->featured_at = $value ? Carbon::now() : null),
            Schema\Integer::make('position'),
            Schema\Integer::make('ownedCount')->property('owned_count'),
            Schema\Integer::make('wishCount')
                ->get(fn (Collection $c, Context $context) => $c->showsWishlistTo($context->getActor()) ? $c->wish_count : 0),
            Schema\Integer::make('tradeCount')->property('trade_count'),
            Schema\Arr::make('preview')
                ->get(fn (Collection $c) => array_map(fn ($path) => $this->images->url($path, true), $c->preview ?? [])),
            Schema\Arr::make('facets')
                ->get(fn (Collection $c) => (object) ($c->facets ?? [])),
            Schema\DateTime::make('createdAt'),
            Schema\DateTime::make('updatedAt'),
            Schema\Boolean::make('canEdit')
                ->get(fn (Collection $c, Context $context) => $context->getActor()->can('update', $c)),
            Schema\Boolean::make('canDelete')
                ->get(fn (Collection $c, Context $context) => $context->getActor()->can('delete', $c)),
            Schema\Boolean::make('canFeature')
                ->get(fn (Collection $c, Context $context) => $context->getActor()->can('feature', $c)),

            // Write-only: the new order of the collection's items.
            Schema\Arr::make('order')
                ->hidden()
                ->writable(fn (Collection $c, Context $context) => $c->exists && $c->isOwnedBy($context->getActor()))
                ->rule('max:'.Collection::MAX_ITEMS)
                ->set(fn (Collection $c, $value) => $this->orders[spl_object_id($c)] = array_map('intval', array_slice((array) $value, 0, Collection::MAX_ITEMS))),

            Schema\Relationship\ToOne::make('user')
                ->type('users')
                ->includable(),
            Schema\Relationship\ToOne::make('type')
                ->type('curio-types')
                ->includable()
                ->writableOnCreate()
                ->requiredOnCreate(),
        ];
    }

    public function sorts(): array
    {
        return [
            SortColumn::make('position'),
            SortColumn::make('updatedAt'),
            SortColumn::make('createdAt'),
            SortColumn::make('ownedCount'),
            SortColumn::make('featuredAt'),
        ];
    }
}
