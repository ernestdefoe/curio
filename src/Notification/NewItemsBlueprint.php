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
 * A collection you follow has new things. The day is the notification's data,
 * so a big import sends one per follower per day, not one per item.
 */
class NewItemsBlueprint implements BlueprintInterface, AlertableInterface, MailableInterface
{
    public function __construct(public Collection $collection, public ?User $from)
    {
    }

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

    public static function getType(): string
    {
        return 'curioNewItems';
    }

    public static function getSubjectModel(): string
    {
        return Collection::class;
    }

    public function getEmailViews(): array
    {
        return ['text' => 'ernestdefoe-curio::emails.plain.new_items', 'html' => 'ernestdefoe-curio::emails.html.new_items'];
    }

    public function getEmailSubject(TranslatorInterface $translator): string
    {
        return $translator->trans('ernestdefoe-curio.email.new_items.subject', ['name' => $this->collection->name]);
    }
}
