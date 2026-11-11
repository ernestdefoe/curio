<?php

namespace Ernestdefoe\Curio\Badges;

use Ernestdefoe\Curio\Event\ItemsChanged;
use Ernestdefoe\Curio\Item;
use Flarum\User\User;
use FoF\Badges\Trigger\MetricInterface;

/**
 * FoF Badges: how many things a member has in their collections (owned or up
 * for trade, never the wishlist), of one type when the badge picks one.
 */
class ItemsMetric implements MetricInterface
{
    use InDateRange;

    public function getType(): string
    {
        return 'curio_items';
    }

    public function getTranslationKey(): string
    {
        return 'ernestdefoe-curio.admin.badges.items';
    }

    public function getEventTriggers(): array
    {
        return [ItemsChanged::class => fn (ItemsChanged $event) => $event->collection->user];
    }

    public function getValue(User $user, array $config = []): int
    {
        $query = Item::query()->where('curio_items.user_id', $user->id)->where('curio_items.status', '!=', 'wishlist');

        if ($type = (int) ($config['curio_type_id'] ?? 0)) {
            $query->join('curio_collections', 'curio_collections.id', '=', 'curio_items.collection_id')
                ->where('curio_collections.type_id', $type);
        }

        $this->inDateRange($query, 'curio_items.created_at', $config);

        return $query->count();
    }

    public function isBoolean(): bool
    {
        return false;
    }

    public function getConfigFields(): array
    {
        return ['curio_type_id' => ['type' => 'select', 'required' => false]];
    }

    public function getExtensionDependencies(): array
    {
        return ['ernestdefoe/curio'];
    }
}
