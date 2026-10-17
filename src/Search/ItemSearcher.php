<?php

namespace Ernestdefoe\Curio\Search;

use Ernestdefoe\Curio\Item;
use Flarum\Search\Database\AbstractSearcher;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

class ItemSearcher extends AbstractSearcher
{
    /** @return Builder<Item> */
    public function getQuery(User $actor): Builder
    {
        return Item::query()->select('curio_items.*')->whereVisibleTo($actor);
    }
}
