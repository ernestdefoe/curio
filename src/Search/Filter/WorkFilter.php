<?php

namespace Ernestdefoe\Curio\Search\Filter;

use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;
use Flarum\Search\ValidateFilterTrait;

/**
 * filter[work]=ID: every copy of one looked-up title, in whoever's collection
 * the reader may open ("Who has this").
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class WorkFilter implements FilterInterface
{
    use ValidateFilterTrait;

    public function getFilterKey(): string
    {
        return 'work';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        /** @var DatabaseSearchState $state */
        $state->getQuery()->where('curio_items.work_id', $negate ? '!=' : '=', (int) $this->asString($value));
    }
}
