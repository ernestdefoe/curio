<?php

namespace Ernestdefoe\Curio\Lookup;

use RuntimeException;

/**
 * A look-up that failed. $outage means the service itself is in trouble (down,
 * busy, rate-limited), as opposed to it not knowing the thing asked for.
 * $unauthorized is a 401: a cached token went stale. $status is the HTTP
 * status, when there was one.
 */
class LookupException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $outage = false, public readonly bool $unauthorized = false, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }
}
