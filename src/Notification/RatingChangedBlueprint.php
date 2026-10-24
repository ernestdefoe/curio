<?php

namespace Ernestdefoe\Curio\Notification;

/** An item in a collection you follow was rated. */
class RatingChangedBlueprint extends FollowedBlueprint
{
    public static function getType(): string
    {
        return 'curioRatingChanged';
    }

    public static function key(): string
    {
        return 'rating_changed';
    }
}
