<?php

namespace Ernestdefoe\Curio\Lookup;

use RuntimeException;

/**
 * A look-up that failed. $outage means the service itself is in trouble (down,
 * busy, rate-limited), as opposed to it not knowing the thing asked for.
 */
class LookupException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $outage = false)
    {
        parent::__construct($message);
    }
}
