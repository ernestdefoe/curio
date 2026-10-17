<?php

namespace Ernestdefoe\Curio\Listener;

use Ernestdefoe\Curio\Item;
use Flarum\Discussion\Event\Started;

/** The item remembers the discussion it was shown off in (the first one wins). */
class LinkShownItem
{
    public function handle(Started $event): void
    {
        $discussion = $event->discussion;

        if ($discussion->curio_item_id) {
            Item::query()->whereKey($discussion->curio_item_id)->whereNull('discussion_id')->update(['discussion_id' => $discussion->id]);
        }
    }
}
