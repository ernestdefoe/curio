<?php

namespace Ernestdefoe\Curio\Lookup;

use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Log\LoggerInterface;

/**
 * For the optional keyed services, whose failures are misses: one log line a
 * day per kind of failure. Messages come from Http and never hold a key, a
 * token or a URL.
 *
 * @property Cache $cache
 * @property LoggerInterface $log
 */
trait LogsOnce
{
    private function failed(string $service, string $name, LookupException $e): void
    {
        // Resting after a failure is that failure's echo, already logged.
        if (str_ends_with($e->getMessage(), 'is marked down')) {
            return;
        }

        $kind = preg_replace('/[^a-z0-9]+/', '-', strtolower($e->getMessage()));

        if ($this->cache->add("curio.$service.logged.$kind.".date('Ymd'), true, 2 * 86400)) {
            $this->log->warning("[curio] $name barcode look-up skipped: ".$e->getMessage());
        }
    }
}
