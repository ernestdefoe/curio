<?php

namespace Ernestdefoe\Curio\Notification;

/** A collection you follow has new things (owned or to trade). */
class NewItemsBlueprint extends FollowedBlueprint
{
    public static function getType(): string
    {
        return 'curioNewItems';
    }

    public static function key(): string
    {
        return 'new_items';
    }
}
