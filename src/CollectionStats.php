<?php

namespace Ernestdefoe\Curio;

use Carbon\Carbon;
use Ernestdefoe\Curio\Api\OverviewController;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Keeps a collection's counts, its four-cover preview and its facet breakdown
 * on the collection itself, so cards, profiles and the browse page never count
 * rows. Runs after every item write: three small indexed queries, and the
 * browse page counts again.
 */
class CollectionStats
{
    public function __construct(private Cache $cache)
    {
    }

    public function refresh(Collection $collection): void
    {
        $items = Item::query()->where('collection_id', $collection->id);

        $counts = (clone $items)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $collection->owned_count = (int) ($counts['owned'] ?? 0) + (int) ($counts['trade'] ?? 0);
        $collection->wish_count = (int) ($counts['wishlist'] ?? 0);
        $collection->trade_count = (int) ($counts['trade'] ?? 0);

        $collection->preview = (clone $items)
            ->where('status', '!=', 'wishlist')
            ->with('work')
            ->orderBy('position')
            ->orderBy('id')
            ->limit(8)
            ->get()
            ->map(fn (Item $item) => $item->coverPath())
            ->filter()
            ->take(4)
            ->values()
            ->all();

        $collection->facets = (clone $items)
            ->where('status', '!=', 'wishlist')
            ->whereNotNull('facet')
            ->selectRaw('facet, count(*) as total')
            ->groupBy('facet')
            ->orderByDesc('total')
            ->limit(12)
            ->pluck('total', 'facet')
            ->map(fn ($n) => (int) $n)
            ->all();

        $collection->updated_at = Carbon::now();
        $collection->saveQuietly();
        OverviewController::forget($this->cache);
    }
}
