<?php

namespace Ernestdefoe\Curio\Access;

use Ernestdefoe\Curio\Collection;
use Ernestdefoe\Curio\Item;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

/** Members change only their own collections and items; moderators may remove anything and feature collections. */
class CollectionPolicy extends AbstractPolicy
{
    public function update(User $actor, Collection|Item $model): ?string
    {
        return $model->user_id === $actor->id && $actor->hasPermission('ernestdefoe-curio.create') ? $this->allow() : $this->deny();
    }

    public function delete(User $actor, Collection|Item $model): ?string
    {
        return $model->user_id === $actor->id || $actor->hasPermission('ernestdefoe-curio.moderate') ? $this->allow() : $this->deny();
    }

    public function feature(User $actor, Collection $model): ?string
    {
        return $actor->hasPermission('ernestdefoe-curio.moderate') ? $this->allow() : $this->deny();
    }
}
