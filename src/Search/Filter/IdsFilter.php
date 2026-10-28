<?php

namespace Ernestdefoe\Curio\Search\Filter;

use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;
use Flarum\Search\ValidateFilterTrait;

/**
 * filter[ids]=1,2,3: these items (the "Most owned" ranking's), at most 50,
 * as visible to the reader as any other.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class IdsFilter implements FilterInterface
{
    use ValidateFilterTrait;

    public function getFilterKey(): string
    {
        return 'ids';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        /** @var DatabaseSearchState $state */
        $ids = array_slice(array_values(array_filter(array_map('intval', explode(',', $this->asString($value))))), 0, 50);
        $state->getQuery()->{$negate ? 'whereNotIn' : 'whereIn'}('curio_items.id', $ids ?: [0]);
    }
}
