<?php

namespace Ernestdefoe\Curio\Job;

use Ernestdefoe\Curio\Item;
use Ernestdefoe\Curio\Notification\WishlistMatchBlueprint;
use Flarum\Notification\NotificationSyncer;
use Flarum\Queue\AbstractJob;
use Flarum\User\User;

/**
 * An item went up for trade: tell the members who have the same title on
 * their wishlist and who may see the offer. Carries an id, so a job that waits
 * reads the item as it is when it runs (taken back off trade = nobody told).
 */
class NotifyWishlists extends AbstractJob
{
    /** Popular titles are wanted by many; nobody needs to hear about one offer from 500 strangers' forum. */
    private const MAX_RECIPIENTS = 200;

    public function __construct(public int $itemId)
    {
        parent::__construct();
    }

    public function handle(NotificationSyncer $notifications): void
    {
        $item = Item::query()->with('collection')->find($this->itemId);

        if (! $item || $item->status !== 'trade' || ! $item->work_id) {
            return;
        }

        $wanting = User::query()
            ->whereIn('id', Item::query()->select('user_id')->where('work_id', $item->work_id)->where('status', 'wishlist'))
            ->where('id', '!=', $item->user_id)
            ->limit(self::MAX_RECIPIENTS)
            ->get()
            // A wish is not a licence to look: only those who can see the offer hear of it.
            ->filter(fn (User $user) => Item::query()->whereVisibleTo($user)->whereKey($item->id)->exists());

        if ($wanting->isNotEmpty()) {
            $notifications->sync(new WishlistMatchBlueprint($item, User::query()->find($item->user_id)), $wanting->values()->all());
        }
    }
}
