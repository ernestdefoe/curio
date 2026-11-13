<?php

namespace Ernestdefoe\Curio\Api;

use Ernestdefoe\Curio\Collection;
use Ernestdefoe\Curio\Type;
use Flarum\User\User;

/** The authors one request has asked about, counted together the first time a count is read. */
final class CollectorBatch
{
    /** @var array<int, true> */
    private array $pending = [];

    /** @var array<int, int> */
    private array $counts = [];

    public function __construct(private User $actor)
    {
    }

    public function want(int $id): void
    {
        if (! array_key_exists($id, $this->counts)) {
            $this->pending[$id] = true;
        }
    }

    public function count(int $id): ?int
    {
        if ($this->pending) {
            $ids = array_keys($this->pending);
            $this->pending = [];
            $this->counts += array_fill_keys($ids, 0);

            Collection::query()
                ->whereIn('user_id', $ids)
                ->whereIn('visibility', $this->actor->isGuest() ? ['public'] : ['public', 'members'])
                ->whereIn('type_id', Type::query()->where('enabled', true)->select('curio_types.id'))
                ->groupBy('user_id')
                ->selectRaw('user_id, count(*) as aggregate')
                ->toBase()
                ->get()
                ->each(fn ($row) => $this->counts[(int) $row->user_id] = (int) $row->aggregate);
        }

        return ($this->counts[$id] ?? 0) ?: null;
    }
}
