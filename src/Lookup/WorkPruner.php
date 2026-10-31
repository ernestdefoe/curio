<?php

namespace Ernestdefoe\Curio\Lookup;

use Carbon\Carbon;
use Ernestdefoe\Curio\Storage\Images;
use Ernestdefoe\Curio\Work;
use Ernestdefoe\Curio\WorkText;

/**
 * Deletes works no item uses any more, with their cover, their texts and the
 * barcodes that pointed to them (a later scan looks the box up afresh), so
 * nothing keeps refreshing titles nobody owns.
 */
class WorkPruner
{
    public function __construct(private Images $images)
    {
    }

    /**
     * @param list<int|null>|null $ids these works, after an item let go of them;
     *                                 null = every work stored over a day ago
     *                                 (look-ups that were never added)
     * @return int how many were deleted
     */
    public function prune(?array $ids = null): int
    {
        $query = Work::query()
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('curio_items')->whereColumn('curio_items.work_id', 'curio_works.id'));

        if ($ids === null) {
            $query->where('created_at', '<', Carbon::now()->subDay());
        } elseif (! $ids = array_values(array_unique(array_filter(array_map('intval', $ids))))) {
            return 0;
        } else {
            $query->whereIn('id', $ids);
        }

        $deleted = 0;
        $query->select(['id', 'cover'])->chunkById(500, function ($works) use (&$deleted) {
            $ids = $works->pluck('id')->all();

            Work::query()->getConnection()->table('curio_barcodes')->whereIn('work_id', $ids)->delete();
            WorkText::query()->whereIn('work_id', $ids)->delete();
            $deleted += Work::query()->whereIn('id', $ids)->delete();
            $works->each(fn (Work $work) => $this->images->delete($work->cover));
        });

        return $deleted;
    }
}
