<?php

namespace Ernestdefoe\Curio\Api;

use Ernestdefoe\Curio\Item;
use Ernestdefoe\Curio\Settings;
use Ernestdefoe\Curio\Work;
use Ernestdefoe\Marquee\Link;
use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

/**
 * An item's title as Marquee knows it, for "Show it off": the discussion opens
 * with Marquee's card, and when the title already has a discussion the reader
 * can see, the button goes there instead of starting a second one. Only on the
 * item's own page, only with Marquee enabled: three indexed queries, and a
 * fourth for a title that only appears among another card's related titles.
 * An edition (Deluxe, GOTY) or DLC is its game here, when the forum says so:
 * "Pragmata Deluxe" shows off in Pragmata's discussion (Wlork, Discuss
 * d/40107 #124).
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

        $work = Work::query()->find($item->work_id, ['source', 'source_id', 'title', 'creator', 'year', 'base_id', 'base_title']);
        $kind = self::KINDS[$work->source ?? ''] ?? null;

        if (! $kind) {
            return null;
        }

        $base = $kind === 'game' && $work->base_id && $work->base_title
            && resolve(SettingsRepositoryInterface::class)->get(Settings::EDITIONS) ? $work->base_id : null;

        // The game's card first, then the edition's own (shown off before 1.23 or with the setting off).
        $media = Discussion::query()->getConnection()->table('marquee_media')
            ->where('kind', $kind)->whereIn('external_id', array_filter([$base, $work->source_id]))
            ->pluck('external_id', 'id')->sortBy(fn ($external) => $external !== ($base ?? $work->source_id))->keys()->all();

        $discussion = $media ? self::discussion($media, $actor) : null;

        // A new discussion is the game's, so the next edition finds it too.
        return [
            'kind' => $kind,
            'externalId' => (string) ($base ?? $work->source_id),
            'title' => $base ? $work->base_title : $work->title,
            'year' => $base ? null : ($work->year ? (int) $work->year : null),
            // Albums are titled "Artist – Album" by Marquee.
            'artist' => $kind === 'music' ? $work->creator : null,
            'discussion' => $discussion ? ['id' => (string) $discussion->id, 'slug' => (string) $discussion->slug] : null,
        ];
    }

    /**
     * The discussion whose card is this title, else one that shows it among
     * its related titles (the other games under the same banner).
     *
     * @param list<int> $media Marquee title ids, the preferred one first
     */
    private static function discussion(array $media, User $actor): ?Discussion
    {
        if (! class_exists(Link::class)) {
            return self::discussionFromColumns($media, $actor);
        }

        // Marquee 1.21+: its own tables, both looked up by their indexed media_id.
        return Discussion::query()->whereVisibleTo($actor)
            ->whereIn('id', fn ($q) => $q->select('discussion_id')->from('marquee_discussions')->whereIn('media_id', $media))
            ->with('marqueeLink')->orderBy('id')->limit(20)->get(['id', 'slug'])
            ->sortBy(fn (Discussion $d) => [array_search($d->getRelationValue('marqueeLink')?->media_id, $media), $d->id])->first()
            ?? Discussion::query()->whereVisibleTo($actor)
                ->whereIn('id', fn ($q) => $q->select('discussion_id')->from('marquee_related')->whereIn('media_id', $media))
                ->orderBy('id')->first(['id', 'slug']);
    }

    /**
     * Marquee 1.20 and older kept the link on `discussions`, the related titles
     * as a JSON list of ids ("[33,34]"). Only until a forum updates Marquee:
     * Marquee 1.21 refuses older Curio releases, so this goes with the next one.
     *
     * @param list<int> $media
     */
    private static function discussionFromColumns(array $media, User $actor): ?Discussion
    {
        return Discussion::query()->whereVisibleTo($actor)
            ->whereIn('marquee_media_id', $media)->orderBy('id')->limit(20)->get(['id', 'slug', 'marquee_media_id'])
            ->sortBy(fn ($d) => [array_search($d->getAttribute('marquee_media_id'), $media), $d->id])->first()
            ?? Discussion::query()->whereVisibleTo($actor)
                ->where(function ($q) use ($media) {
                    foreach ($media as $id) {
                        $q->orWhere('marquee_related', "[$id]")
                            ->orWhere('marquee_related', 'like', "[$id,%")
                            ->orWhere('marquee_related', 'like', "%,$id,%")
                            ->orWhere('marquee_related', 'like', "%,$id]");
                    }
                })
                ->orderBy('id')->first(['id', 'slug']);
    }
}
