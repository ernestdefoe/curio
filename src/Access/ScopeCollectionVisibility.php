<?php

namespace Ernestdefoe\Curio\Access;

use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who sees a collection: moderators all of them; everyone else needs the view
 * permission, then sees public ones, members-only ones when signed in, and
 * always their own. A collection whose owner they cannot see is hidden too.
 */
class ScopeCollectionVisibility
{
    public function __invoke(User $actor, Builder $query): void
    {
        if ($actor->hasPermission('ernestdefoe-curio.moderate')) {
            return;
        }

        if (! $actor->hasPermission('ernestdefoe-curio.view')) {
            $query->whereRaw('1 = 0');

            return;
        }

        $table = $query->getModel()->getTable();

        $query->where(function (Builder $q) use ($actor, $table) {
            $q->where(function (Builder $q) use ($actor, $table) {
                $q->whereIn("$table.visibility", $actor->isGuest() ? ['public'] : ['public', 'members'])
                    ->whereIn("$table.user_id", User::query()->whereVisibleTo($actor)->select('users.id'));
            });

            if (! $actor->isGuest()) {
                $q->orWhere("$table.user_id", $actor->id);
            }
        });
    }
}
