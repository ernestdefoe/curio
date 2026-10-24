<?php

namespace Ernestdefoe\Curio\Notification;

/** An item in a collection you follow was started or finished. */
class ProgressChangedBlueprint extends FollowedBlueprint
{
    public static function getType(): string
    {
        return 'curioProgressChanged';
    }

    public static function key(): string
    {
        return 'progress_changed';
    }
}
