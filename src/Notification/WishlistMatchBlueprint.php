<?php

namespace Ernestdefoe\Curio\Notification;

use Ernestdefoe\Curio\Item;
use Flarum\Database\AbstractModel;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\MailableInterface;
use Flarum\User\User;

/** Something on your wishlist is open to trade. One per offered item, however often it is re-offered. */
class WishlistMatchBlueprint implements BlueprintInterface, AlertableInterface, MailableInterface
{
    public function __construct(public Item $item, public ?User $from)
    {
    }

    public function getFromUser(): ?User
    {
        return $this->from;
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->item;
    }

    public function getData(): mixed
    {
        return null;
    }

    public static function getType(): string
    {
        return 'curioWishlistMatch';
    }

    public static function getSubjectModel(): string
    {
        return Item::class;
    }

    public function getEmailViews(): array
    {
        return ['text' => 'ernestdefoe-curio::emails.plain.match', 'html' => 'ernestdefoe-curio::emails.html.match'];
    }

    public function getEmailSubject(TranslatorInterface $translator): string
    {
        return $translator->trans('ernestdefoe-curio.email.match.subject', ['title' => $this->item->title]);
    }
}
