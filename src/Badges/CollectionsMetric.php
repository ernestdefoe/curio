<?php

namespace Ernestdefoe\Curio\Badges;

use Ernestdefoe\Curio\Collection;
use Ernestdefoe\Curio\Event\CollectionCreated;
use Flarum\User\User;
use FoF\Badges\Trigger\MetricInterface;

/** FoF Badges: how many collections a member has made, of one type when the badge picks one. */
class CollectionsMetric implements MetricInterface
{
    use InDateRange;

    public function getType(): string
    {
        return 'curio_collections';
    }

    public function getTranslationKey(): string
    {
        return 'ernestdefoe-curio.admin.badges.collections';
    }

    public function getEventTriggers(): array
    {
        return [CollectionCreated::class => fn (CollectionCreated $event) => $event->collection->user];
    }

    public function getValue(User $user, array $config = []): int
    {
        $query = Collection::query()->where('user_id', $user->id);

        if ($type = (int) ($config['curio_type_id'] ?? 0)) {
            $query->where('type_id', $type);
        }

        $this->inDateRange($query, 'created_at', $config);

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
