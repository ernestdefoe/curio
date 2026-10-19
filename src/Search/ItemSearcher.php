<?php

namespace Ernestdefoe\Curio\Search;

use Ernestdefoe\Curio\Item;
use Flarum\Search\Database\AbstractSearcher;
use Flarum\Search\Database\DatabaseSearchState;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

class ItemSearcher extends AbstractSearcher
{
    /** Sorts where an empty value goes last either way ("Highest rated" ends with the unrated ones). */
    private const NULLS_LAST = ['rating', 'year'];

    /** @return Builder<Item> */
    public function getQuery(User $actor): Builder
    {
        return Item::query()->select('curio_items.*')->whereVisibleTo($actor);
    }

    /**
     * PostgreSQL sorts NULL first when descending, the others when ascending:
     * an explicit "is null" first makes every database agree.
     */
    protected function applySort(DatabaseSearchState $state, ?array $sort = null, bool $sortIsDefault = false): void
    {
        $query = $state->getQuery();

        foreach (is_array($sort) && ! $sortIsDefault ? array_keys($sort) : [] as $field) {
            if (in_array($field, self::NULLS_LAST, true)) {
                $query->orderByRaw($query->getQuery()->getGrammar()->wrap('curio_items.'.$field).' is null');
            }
        }

        parent::applySort($state, $sort, $sortIsDefault);
    }
}
