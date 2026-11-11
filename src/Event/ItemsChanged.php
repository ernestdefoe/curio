<?php

namespace Ernestdefoe\Curio\Event;

use Ernestdefoe\Curio\Collection;

/** The number of items a collection holds (not counting its wishlist) went up or down. */
class ItemsChanged
{
    public function __construct(public Collection $collection)
    {
    }
}
