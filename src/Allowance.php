<?php

namespace Ernestdefoe\Curio;

use Illuminate\Contracts\Cache\Repository as Cache;

/** A use counted against a limit, in a window that starts with the first use. */
final class Allowance
{
    /** Counts one use of $key; false once more than $limit were counted in the window. */
    public static function spend(Cache $cache, string $key, int $limit, int $ttl): bool
    {
        $cache->add($key, 0, $ttl);

        return $cache->increment($key) <= $limit;
    }
}
