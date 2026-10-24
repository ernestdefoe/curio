<?php

namespace Ernestdefoe\Curio\Notification;

use Carbon\Carbon;
use Ernestdefoe\Curio\Collection;
use Flarum\Database\AbstractModel;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\MailableInterface;
use Flarum\User\User;

/**
 * Something happened in a collection you follow. The day is the
 * notification's data, so a busy collection sends each follower at most one
 * of each kind a day, not one per item.
 */
abstract class FollowedBlueprint implements BlueprintInterface, AlertableInterface, MailableInterface
{
    public function __construct(public Collection $collection, public ?User $from)
    {
    }

    /** The translation group under ernestdefoe-curio.email: new_items, wishlist_added… */
    abstract public static function key(): string;

    public function getFromUser(): ?User
    {
        return $this->from;
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->collection;
    }

    public function getData(): mixed
    {
        return ['day' => Carbon::now()->toDateString()];
    }

    public static function getSubjectModel(): string
    {
        return Collection::class;
    }

    public function getEmailViews(): array
    {
        return ['text' => 'ernestdefoe-curio::emails.plain.followed', 'html' => 'ernestdefoe-curio::emails.html.followed'];
    }

    public function getEmailSubject(TranslatorInterface $translator): string
    {
        return $translator->trans('ernestdefoe-curio.email.'.static::key().'.subject', ['name' => $this->collection->name, 'owner' => $this->from->display_name ?? '']);
    }
}
