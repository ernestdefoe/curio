<?php

namespace Ernestdefoe\Curio\Lookup;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Keeps the forum inside a free service's limits: never more than so many
 * requests a second (the next waits for the next second), never more than so
 * many a day (then the service rests until tomorrow).
 */
class Budget
{
    public function __construct(private Cache $cache)
    {
    }

    /** @throws LookupException when today's allowance is spent */
    public function spend(string $service, int $perSecond, int $perDay): void
    {
        $day = "curio.$service.day.".date('Ymd');
        $this->cache->add($day, 0, 2 * 86400);
        if ($this->cache->increment($day) > $perDay) {
            throw new LookupException("$service daily allowance spent", true);
        }

        $second = "curio.$service.rate.".time();
        $this->cache->add($second, 0, 5);
        if ($this->cache->increment($second) > $perSecond) {
            usleep((int) ((1 - fmod(microtime(true), 1)) * 1000000) + 20000);
        }
    }
}
