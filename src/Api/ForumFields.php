<?php

namespace Ernestdefoe\Curio\Api;

use Flarum\Api\Context;
use Flarum\Api\Schema;

/** What the forum's frontend needs to know of the actor's Curio permissions. */
class ForumFields
{
    /** @return list<Schema\Boolean> */
    public function __invoke(): array
    {
        return [
            Schema\Boolean::make('canViewCurio')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('ernestdefoe-curio.view')),
            Schema\Boolean::make('canCreateCurio')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('ernestdefoe-curio.create')),
            Schema\Boolean::make('canModerateCurio')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('ernestdefoe-curio.moderate')),
        ];
    }
}
