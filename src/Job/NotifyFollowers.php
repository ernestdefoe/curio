<?php

namespace Ernestdefoe\Curio\Job;

use Ernestdefoe\Curio\Collection;
use Ernestdefoe\Curio\Notification\NewItemsBlueprint;
use Flarum\Notification\NotificationSyncer;
use Flarum\Queue\AbstractJob;
use Flarum\User\User;

/** A followed collection grew: tell its followers who can still see it (one per day). */
class NotifyFollowers extends AbstractJob
{
    public function __construct(public int $collectionId)
    {
        parent::__construct();
    }

    public function handle(NotificationSyncer $notifications): void
    {
        $collection = Collection::query()->find($this->collectionId);

        if (! $collection) {
            return;
        }

        $followers = User::query()
            ->whereIn('id', fn ($q) => $q->select('user_id')->from('curio_follows')->where('collection_id', $collection->id))
            ->where('id', '!=', $collection->user_id)
            ->get()
            ->filter(fn (User $user) => Collection::query()->whereVisibleTo($user)->whereKey($collection->id)->exists());

        if ($followers->isNotEmpty()) {
            $notifications->sync(new NewItemsBlueprint($collection, User::query()->find($collection->user_id)), $followers->values()->all());
        }
    }
}
