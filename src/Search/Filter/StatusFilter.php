<?php

namespace Ernestdefoe\Curio\Search\Filter;

use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;
use Flarum\Search\ValidateFilterTrait;

/**
 * @implements FilterInterface<DatabaseSearchState>
 */
class StatusFilter implements FilterInterface
{
    use ValidateFilterTrait;

    public function getFilterKey(): string
    {
        return 'status';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        /** @var DatabaseSearchState $state */
        // "owned" includes items open to trade: they are still on the shelf.
        $status = $this->asString($value);
        $statuses = $status === 'owned' ? ['owned', 'trade'] : [$status];
        $state->getQuery()->{$negate ? 'whereNotIn' : 'whereIn'}('curio_items.status', $statuses);
    }
}
