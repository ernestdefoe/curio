<?php

namespace Ernestdefoe\Curio\Api;

use Closure;
use Ernestdefoe\Curio\Settings;
use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use WeakMap;

/**
 * The collector badge: how many of a member's collections the reader may see,
 * by each collection's own "Who can see this?" (public to everyone, members-only
 * to signed-in readers, private never; moderators and the owner count no more
 * than anyone else). Absent when the badge is switched off or the reader can't
 * see collections, and null for a member with none to show.
 *
 * The count is left pending until the whole document is built, so every author
 * on a page is counted in one query.
 */
class CollectorField
{
    /** @var WeakMap<object, CollectorBatch> one batch per request */
    private static ?WeakMap $batches = null;

    public function __construct(private SettingsRepositoryInterface $settings)
    {
    }

    public function __invoke(): array
    {
        return [
            Schema\Integer::make('curioCollections')
                ->nullable()
                ->visible(fn (User $user, Context $context) => in_array($this->settings->get(Settings::COLLECTOR_PLACE), Settings::COLLECTOR_PLACES_SHOWN, true)
                    && $context->getActor()->hasPermission('ernestdefoe-curio.view'))
                ->get(fn (User $user, Context $context) => self::count($user, $context)),
        ];
    }

    private static function count(User $user, Context $context): Closure
    {
        self::$batches ??= new WeakMap();
        $batch = self::$batches[$context->request] ??= new CollectorBatch($context->getActor());
        $batch->want($user->id);

        return fn () => $batch->count($user->id);
    }
}
