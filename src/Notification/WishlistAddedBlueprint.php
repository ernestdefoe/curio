<?php

namespace Ernestdefoe\Curio\Notification;

/** Something went on the wishlist of a collection you follow (only sent to followers who may see that wishlist). */
class WishlistAddedBlueprint extends FollowedBlueprint
{
    public static function getType(): string
    {
        return 'curioWishlistAdded';
    }

    public static function key(): string
    {
        return 'wishlist_added';
    }
}
