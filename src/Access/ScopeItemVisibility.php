<?php

namespace Ernestdefoe\Curio\Access;

use Ernestdefoe\Curio\Collection;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/** An item is seen by whoever sees its collection; wanted items only where the wishlist is public. */
class ScopeItemVisibility
{
    public function __invoke(User $actor, Builder $query): void
    {
        if ($actor->hasPermission('ernestdefoe-curio.moderate')) {
            return;
        }

        $table = $query->getModel()->getTable();
        $visible = Collection::query()->whereVisibleTo($actor);

        $query->whereIn("$table.collection_id", (clone $visible)->select('curio_collections.id'))
            ->where(function (Builder $q) use ($actor, $table, $visible) {
                $q->where("$table.status", '!=', 'wishlist')
                    ->orWhereIn("$table.collection_id", (clone $visible)->where('curio_collections.wishlist_public', true)->select('curio_collections.id'));

                if (! $actor->isGuest()) {
                    $q->orWhere("$table.user_id", $actor->id);
                }
            });
    }
}
