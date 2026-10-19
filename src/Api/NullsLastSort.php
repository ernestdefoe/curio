<?php

namespace Ernestdefoe\Curio\Api;

use Flarum\Api\Sort\SortColumn;
use Illuminate\Support\Str;
use Tobyz\JsonApiServer\Context;

/**
 * A column sort that keeps empty values last both ways ("Highest rated" puts
 * unrated items at the end). PostgreSQL sorts NULL first when descending; the
 * other databases sort it first when ascending.
 */
class NullsLastSort extends SortColumn
{
    public function apply(object $query, string $direction, Context $context): void
    {
        $column = $query->getModel()->qualifyColumn($this->column ?: Str::snake($this->name));

        $query->orderByRaw($query->getQuery()->getGrammar()->wrap($column).' is null')->orderBy($column, $direction);
    }
}
