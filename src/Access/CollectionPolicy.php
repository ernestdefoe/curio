<?php

namespace Ernestdefoe\Curio\Access;

use Ernestdefoe\Curio\Collection;
use Ernestdefoe\Curio\Item;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

/**
 * Members change only their own collections and items. Moderators may change,
 * remove and feature anyone's collection; with "Edit anyone's items" they also
 * add, change and remove the items in anyone's collection.
 */
class CollectionPolicy extends AbstractPolicy
{
    public const EDIT_ITEMS = 'ernestdefoe-curio.editItems';

    public function update(User $actor, Collection|Item $model): ?string
    {
        if ($model->user_id === $actor->id && $actor->hasPermission('ernestdefoe-curio.create')) {
            return $this->allow();
        }

        return $actor->hasPermission($model instanceof Item ? self::EDIT_ITEMS : 'ernestdefoe-curio.moderate') ? $this->allow() : $this->deny();
    }

    public function delete(User $actor, Collection|Item $model): ?string
    {
        return $model->user_id === $actor->id || $actor->hasPermission('ernestdefoe-curio.moderate')
            || ($model instanceof Item && $actor->hasPermission(self::EDIT_ITEMS)) ? $this->allow() : $this->deny();
    }

    /** Putting a new item in this collection: its owner, or someone who edits anyone's items. */
    public function addItems(User $actor, Collection $model): ?string
    {
        return ($model->user_id === $actor->id && $actor->hasPermission('ernestdefoe-curio.create')) || $actor->hasPermission(self::EDIT_ITEMS)
            ? $this->allow() : $this->deny();
    }

    public function feature(User $actor, Collection $model): ?string
    {
        return $actor->hasPermission('ernestdefoe-curio.moderate') ? $this->allow() : $this->deny();
    }
}
