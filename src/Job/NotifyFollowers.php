<?php

namespace Ernestdefoe\Curio\Job;

use Ernestdefoe\Curio\Collection;
use Ernestdefoe\Curio\Item;
use Ernestdefoe\Curio\Notification\FollowedBlueprint;
use Ernestdefoe\Curio\Notification\NewItemsBlueprint;
use Ernestdefoe\Curio\Notification\ProgressChangedBlueprint;
use Ernestdefoe\Curio\Notification\RatingChangedBlueprint;
use Ernestdefoe\Curio\Notification\WishlistAddedBlueprint;
use Flarum\Notification\Notification;
use Flarum\Notification\NotificationSyncer;
use Flarum\Queue\AbstractJob;
use Flarum\User\User;

/**
 * Something happened in a followed collection: tell its followers who can
 * still see it, and see the item it is about (a private wishlist stays
 * private). Each follower gets at most one of each kind a day; followers
 * already told today are not checked again.
 */
class NotifyFollowers extends AbstractJob
{
    /** @var array<string, class-string<FollowedBlueprint>> */
    public const KINDS = [
        'new' => NewItemsBlueprint::class,
        'wishlist' => WishlistAddedBlueprint::class,
        'progress' => ProgressChangedBlueprint::class,
        'rating' => RatingChangedBlueprint::class,
    ];

    public function __construct(public int $collectionId, public string $kind = 'new', public ?int $itemId = null)
    {
        parent::__construct();
    }

    public function handle(NotificationSyncer $notifications): void
    {
        $class = self::KINDS[$this->kind] ?? null;
        $collection = $class ? Collection::query()->find($this->collectionId) : null;

        if (! $collection) {
            return;
        }

        $owner = User::query()->find($collection->user_id);
        $blueprint = new $class($collection, $owner);

        // Already told today: kept as they are (a sync drops anyone left out).
        $told = Notification::matchingBlueprint($blueprint)->pluck('user_id')->flip();

        $followers = User::query()
            ->whereIn('id', fn ($q) => $q->select('user_id')->from('curio_follows')->where('collection_id', $collection->id))
            ->where('id', '!=', $collection->user_id)
            ->get()
            ->filter(fn (User $user) => isset($told[$user->id]) || ($this->itemId
                ? Item::query()->whereVisibleTo($user)->whereKey($this->itemId)->exists()
                : Collection::query()->whereVisibleTo($user)->whereKey($collection->id)->exists()));

        if ($followers->contains(fn (User $user) => ! isset($told[$user->id]))) {
            $notifications->sync($blueprint, $followers->values()->all());
        }
    }
}
