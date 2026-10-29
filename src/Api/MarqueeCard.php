<?php

namespace Ernestdefoe\Curio\Api;

use Ernestdefoe\Curio\Item;
use Ernestdefoe\Curio\Lookup\Sources\Igdb;
use Ernestdefoe\Curio\Work;
use Flarum\User\User;

/**
 * What Curio's buttons on a Marquee card need, sent with the discussion page
 * (never with lists): the title's stored work, the reader's own copy (their
 * "Add to collection" becomes "Edit") and how many members have it. Two
 * indexed queries, one when nobody has added the title yet.
 *
 * Marquee and Curio's own IGDB source use the same game ids, so a game can be
 * stored under either key: both count. New copies go under Curio's key when
 * Curio has IGDB set up, the same work Curio's own search adds to.
 */
class MarqueeCard
{
    /** Marquee's kind → the Curio source its titles are stored under (the same ids). */
    public const SOURCES = [
        'movie' => 'marquee-movie',
        'tv' => 'marquee-tv',
        'game' => 'marquee-game',
        'music' => 'deezer',
        'book' => 'openlibrary',
    ];

    /**
     * @return array{source: string, workId: string|null, workIds: string|null, itemId: string|null, owning: int, owners: int}|null
     */
    public static function for(?object $media, User $actor): ?array
    {
        $source = self::SOURCES[$media->kind ?? ''] ?? null;

        if (! $source || ! isset($media->external_id)) {
            return null;
        }

        $sources = [$source];
        if ($source === 'marquee-game') {
            $sources = resolve(Igdb::class)->configured() ? ['igdb', 'marquee-game'] : ['marquee-game', 'igdb'];
        }

        $card = ['source' => $sources[0], 'workId' => null, 'workIds' => null, 'itemId' => null, 'owning' => 0, 'owners' => 0];
        $works = Work::query()->whereIn('source', $sources)->where('source_id', (string) $media->external_id)->pluck('id', 'source');

        if ($works->isEmpty()) {
            return $card;
        }

        $workId = $works[$sources[0]] ?? $works->first();
        $query = Item::query()->whereVisibleTo($actor)->whereIn('curio_items.work_id', $works->values()->all());
        $wrap = fn (string $column) => $query->getQuery()->getGrammar()->wrap($column);
        $status = $wrap('curio_items.status');
        $user = $wrap('curio_items.user_id');
        $id = $wrap('curio_items.id');

        // Members who have it (owned or to trade, as on the item's own page), and the reader's
        // copy: one on the shelf first, else one on their wishlist.
        $query->selectRaw("count(distinct case when $status <> 'wishlist' then $user end) as owners")
            ->selectRaw("min(case when $user = ? and $status <> 'wishlist' then $id end) as owned_id", [$actor->id ?? 0])
            ->selectRaw("min(case when $user = ? then $id end) as any_id", [$actor->id ?? 0]);

        $row = $query->toBase()->first();
        $mine = $actor->isGuest() ? null : ($row->owned_id ?? $row->any_id ?? null);

        return [
            'source' => $sources[0],
            'workId' => (string) $workId,
            'workIds' => $works->values()->implode(','),
            'itemId' => $mine ? (string) $mine : null,
            'owning' => $mine && $row->owned_id ? 1 : 0,
            'owners' => (int) ($row->owners ?? 0),
        ];
    }
}
