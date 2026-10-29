<?php

namespace Ernestdefoe\Curio\Api;

use Ernestdefoe\Curio\Item;
use Ernestdefoe\Curio\Work;
use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\User\User;

/**
 * An item's title as Marquee knows it, for "Show it off": the discussion opens
 * with Marquee's card, and when the title already has a discussion the reader
 * can see, the button goes there instead of starting a second one. Only on the
 * item's own page, only with Marquee enabled: three indexed queries at most.
 */
class MarqueeTitle
{
    /** A Curio source → Marquee's kind (the same ids, see MarqueeCard::SOURCES). */
    private const KINDS = [
        'marquee-game' => 'game',
        'igdb' => 'game',
        'marquee-movie' => 'movie',
        'marquee-tv' => 'tv',
        'deezer' => 'music',
        'openlibrary' => 'book',
    ];

    /**
     * @return array{kind: string, externalId: string, title: string, year: int|null, artist: string|null, discussion: array{id: string, slug: string}|null}|null
     */
    public static function for(Item $item, User $actor): ?array
    {
        if (! $item->work_id || ! resolve(ExtensionManager::class)->isEnabled('ernestdefoe-marquee')) {
            return null;
        }

        $work = Work::query()->find($item->work_id, ['source', 'source_id', 'title', 'creator', 'year']);
        $kind = self::KINDS[$work->source ?? ''] ?? null;

        if (! $kind) {
            return null;
        }

        $media = Discussion::query()->getConnection()->table('marquee_media')
            ->where('kind', $kind)->where('external_id', $work->source_id)->value('id');

        $discussion = $media ? Discussion::query()->whereVisibleTo($actor)
            ->where('marquee_media_id', $media)->orderBy('id')->first(['id', 'slug']) : null;

        return [
            'kind' => $kind,
            'externalId' => (string) $work->source_id,
            'title' => $work->title,
            'year' => $work->year ? (int) $work->year : null,
            // Albums are titled "Artist – Album" by Marquee.
            'artist' => $kind === 'music' ? $work->creator : null,
            'discussion' => $discussion ? ['id' => (string) $discussion->id, 'slug' => (string) $discussion->slug] : null,
        ];
    }
}
