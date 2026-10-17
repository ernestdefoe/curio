<?php

namespace Ernestdefoe\Curio\Api;

use Ernestdefoe\Curio\Item;
use Flarum\Api\Context;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Schema;
use Flarum\Discussion\Discussion;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;

/**
 * "Show it off": a discussion about one of the starter's items. The item rides
 * on the discussion page only (never on lists), and only when the reader may
 * see it: a private collection's item never leaks through a public thread.
 */
class DiscussionFields
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function __invoke(): array
    {
        return [
            Schema\Relationship\ToOne::make('curioItem')
                ->type('curio-items')
                ->includable()
                ->visible(fn (Discussion $d, Context $context) => $d->curio_item_id && $context->showing(DiscussionResource::class))
                ->get(fn (Discussion $d, Context $context) => Item::query()->whereVisibleTo($context->getActor())->find($d->curio_item_id)),

            // Write-only, when starting the discussion: the id of one of the starter's own items.
            Schema\Integer::make('curioItemId')
                ->hidden()
                ->nullable()
                ->writableOnCreate()
                ->set(function (Discussion $d, $value, Context $context) {
                    if ($value === null) {
                        return;
                    }

                    $item = Item::query()->find((int) $value);

                    if (! $item || $item->user_id !== $context->getActor()->id || $item->status === 'wishlist') {
                        throw new ValidationException(['curioItemId' => $this->translator->trans('ernestdefoe-curio.api.not_your_item')]);
                    }

                    $d->curio_item_id = $item->id;
                }),
        ];
    }
}
