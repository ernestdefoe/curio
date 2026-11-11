<?php

namespace Ernestdefoe\Curio\Badges;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

trait InDateRange
{
    /** Counts only what was added inside the badge's date range, when it has one. */
    private function inDateRange(Builder $query, string $column, array $config): void
    {
        if ($start = $config['date_range']['start'] ?? null) {
            $query->where($column, '>=', Carbon::parse($start));
        }

        if ($end = $config['date_range']['end'] ?? null) {
            $query->where($column, '<=', Carbon::parse($end));
        }
    }
}
