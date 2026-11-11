<?php

namespace Ernestdefoe\Curio\Event;

use Ernestdefoe\Curio\Collection;

/** A member made a collection (on the forum or by an import). */
class CollectionCreated
{
    public function __construct(public Collection $collection)
    {
    }
}
