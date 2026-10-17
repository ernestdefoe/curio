<?php

namespace Ernestdefoe\Curio\Search\Filter;

use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;
use Flarum\Search\ValidateFilterTrait;

/**
 * @implements FilterInterface<DatabaseSearchState>
 */
class TitleFilter implements FilterInterface
{
    use ValidateFilterTrait;

    public function getFilterKey(): string
    {
        return 'q';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        /** @var DatabaseSearchState $state */
        // Wildcards are dropped rather than escaped: SQLite has no default LIKE escape character.
        $words = mb_strtolower(mb_substr(trim(str_replace(['%', '_'], ' ', $this->asString($value))), 0, 100));
        if ($words === '') {
            return;
        }

        $query = $state->getQuery();
        // Through the grammar, so a table prefix is applied; lower() so PostgreSQL matches like the others.
        $grammar = $query->getQuery()->getGrammar();
        $query->where(fn ($q) => $q->whereRaw('lower('.$grammar->wrap('curio_items.title').') like ?', ["%$words%"])
            ->orWhereRaw('lower('.$grammar->wrap('curio_items.subtitle').') like ?', ["%$words%"]));
    }
}
