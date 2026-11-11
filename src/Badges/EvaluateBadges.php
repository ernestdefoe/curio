<?php

namespace Ernestdefoe\Curio\Badges;

use Ernestdefoe\Curio\Event\CollectionCreated;
use Ernestdefoe\Curio\Event\ItemsChanged;
use FoF\Badges\BadgeAwarder;
use FoF\Badges\Trigger\TriggerEvaluator;

/**
 * Awards the FoF badges a collection's owner now qualifies for. FoF's own
 * listener only knows the events it ships with, so this does its work for
 * Curio's: the same evaluator and awarder, so the badge's own settings apply.
 */
class EvaluateBadges
{
    public function __construct(private TriggerEvaluator $evaluator, private BadgeAwarder $awarder)
    {
    }

    public function handle(CollectionCreated|ItemsChanged $event): void
    {
        $user = $event->collection->user;

        if (! $user) {
            return;
        }

        foreach ($this->evaluator->evaluateForEvent($user, $event::class) as $badge) {
            $this->awarder->award($user, $badge);
        }
    }
}
