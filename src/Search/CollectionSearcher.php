<?php

namespace Ernestdefoe\Curio\Search;

use Ernestdefoe\Curio\Collection;
use Flarum\Search\Database\AbstractSearcher;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

class CollectionSearcher extends AbstractSearcher
{
    /** @return Builder<Collection> */
    public function getQuery(User $actor): Builder
    {
        return Collection::query()->select('curio_collections.*')->whereVisibleTo($actor);
    }
}
