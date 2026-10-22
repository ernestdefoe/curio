<?php

namespace Ernestdefoe\Curio\Search\Filter;

use Ernestdefoe\Curio\Collection;
use Ernestdefoe\Curio\Type;
use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;
use Flarum\Search\ValidateFilterTrait;

/**
 * changed:progress: items whose progress was set (on the shelf, of a type that
 * tracks it). changed:rating: rated items on the shelf. Sorted newest first by
 * the caller (-progressChangedAt / -ratingChangedAt).
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class ChangedFilter implements FilterInterface
{
    use ValidateFilterTrait;

    public function getFilterKey(): string
    {
        return 'changed';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        /** @var DatabaseSearchState $state */
        $query = $state->getQuery();

        match ($this->asString($value)) {
            'progress' => $query->whereNotNull('curio_items.progress_changed_at')->whereNotNull('curio_items.progress')
                ->where('curio_items.status', '!=', 'wishlist')
                ->whereIn('curio_items.collection_id', Collection::query()
                    ->whereIn('type_id', Type::query()->where('progress', true)->select('curio_types.id'))->select('curio_collections.id')),
            'rating' => $query->whereNotNull('curio_items.rating_changed_at')->whereNotNull('curio_items.rating')
                ->where('curio_items.status', '!=', 'wishlist'),
            default => $query->whereRaw('1 = 0'),
        };
    }
}
