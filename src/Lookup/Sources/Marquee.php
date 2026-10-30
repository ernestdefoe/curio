<?php

namespace Ernestdefoe\Curio\Lookup\Sources;

use Ernestdefoe\Curio\Lookup\LookupException;
use Ernestdefoe\Curio\Lookup\Source;
use Closure;
use Throwable;

/**
 * Films, TV and games through Marquee, when it is installed: Curio has no TMDB
 * or IGDB keys of its own and asks Marquee's sources instead.
 */
class Marquee implements Source
{
    /** Marquee's kind → Wikidata property for its id. */
    private const WIKIDATA = ['movie' => 'P4947', 'tv' => 'P4983'];

    private const EXCEPTION = 'Ernestdefoe\Marquee\Source\SourceException';

    /**
     * Marquee's source is reached through two closures, so this class loads
     * (and is analysed) without Marquee installed.
     *
     * @param Closure(string): list<array<string, mixed>> $search
     * @param Closure(string): array<string, mixed> $details
     */
    public function __construct(private string $kind, private Closure $search, private Closure $details)
    {
    }

    public function key(): string
    {
        return 'marquee-'.$this->kind;
    }

    public function search(string $query): array
    {
        try {
            $results = ($this->search)($query);
        } catch (Throwable $e) {
            throw new LookupException('Marquee search failed', self::outage($e));
        }

        return array_map(function (array $r) {
            $subtitle = ($r['subtitle'] ?? '') !== '' ? (string) $r['subtitle'] : null;
            $platforms = $this->kind === 'game' ? self::platforms($subtitle) : [];

            return [
                'id' => (string) $r['id'],
                'title' => (string) $r['title'],
                // A game's line reads "PC, PS5, Switch +4 · Action, RPG": the platforms become choices.
                'creator' => $platforms ? (trim(explode(' · ', (string) $subtitle, 2)[1] ?? '') ?: null) : $subtitle,
                'year' => $r['year'] ?? null,
                'cover' => self::image($r['poster'] ?? null),
                // Marquee 1.13.2+: Expansion, Bundle, Remake… for games.
                'release' => is_string($r['type'] ?? null) ? $r['type'] : null,
                'variants' => array_map(fn (string $short) => ['label' => $short, 'fields' => ['platform' => self::PLATFORMS[$short] ?? $short]], $platforms),
            ];
        }, array_slice($results, 0, 8));
    }

    public function details(string $id): array
    {
        // TMDB and IGDB ids are numbers. Marquee's sources put the id straight into
        // the API path, so nothing else may reach them with the forum's keys.
        if (! ctype_digit($id) || strlen($id) > 12) {
            throw new LookupException("Not a Marquee id: $id");
        }

        try {
            $d = ($this->details)($id);
        } catch (Throwable $e) {
            throw new LookupException('Marquee has no such title', self::outage($e));
        }

        $wikidata = match (true) {
            isset(self::WIKIDATA[$this->kind]) => [self::WIKIDATA[$this->kind], $id],
            $this->kind === 'game' && is_string($d['slug'] ?? null) => ['P5794', $d['slug']],
            default => null,
        };

        $creator = $d['crew']['developer'][0] ?? $d['crew']['director'][0] ?? $d['crew']['creator'][0] ?? null;

        return [
            'title' => (string) $d['title'],
            'creator' => is_string($creator) ? $creator : (is_array($creator) && isset($creator['name']) ? (string) $creator['name'] : null),
            'year' => isset($d['year']) ? (int) $d['year'] : null,
            'cover' => self::image($d['poster'] ?? null),
            'wikidata' => $wikidata,
            'description' => is_string($d['overview'] ?? null) ? $d['overview'] : null,
            // Marquee's game details carry the same facts as Curio's own IGDB look-up.
            'facts' => $this->kind === 'game' ? [
                'developer' => self::names($d['crew']['developer'] ?? []),
                'publisher' => self::names($d['crew']['publisher'] ?? []),
                'released' => is_string($d['date'] ?? null) ? $d['date'] : null,
                'platforms' => self::names($d['platforms'] ?? []),
                'genres' => self::names($d['genres'] ?? []),
            ] : null,
        ];
    }

    /** IGDB's short platform names (as Marquee shows them) → full names, to match a type's options. */
    private const PLATFORMS = [
        'PC' => 'PC (Microsoft Windows)', 'PS5' => 'PlayStation 5', 'PS4' => 'PlayStation 4', 'PS3' => 'PlayStation 3',
        'PS2' => 'PlayStation 2', 'PS1' => 'PlayStation', 'XONE' => 'Xbox One', 'Series X|S' => 'Xbox Series X|S',
        'X360' => 'Xbox 360', 'XBOX' => 'Xbox', 'Switch' => 'Nintendo Switch', 'Switch 2' => 'Nintendo Switch 2',
        'WiiU' => 'Wii U', 'Wii' => 'Wii', 'NGC' => 'GameCube', 'N64' => 'Nintendo 64', 'SNES' => 'SNES', 'NES' => 'NES',
        'GB' => 'Game Boy', 'GBC' => 'Game Boy Color', 'GBA' => 'Game Boy Advance', 'NDS' => 'Nintendo DS',
        '3DS' => 'Nintendo 3DS', 'Vita' => 'PS Vita', 'PSP' => 'PSP', 'Genesis/MegaDrive' => 'Mega Drive / Genesis',
        'Saturn' => 'Saturn', 'DC' => 'Dreamcast', 'Mac' => 'Mac', 'iOS' => 'iOS', 'Android' => 'Android', 'Linux' => 'Linux',
    ];

    /**
     * The platforms in Marquee's game line: its first part when there are two
     * ("PC, PS5 +4 · Action"); a lone part only when every name is a platform
     * (it could be the genres).
     *
     * @return list<string>
     */
    private static function platforms(?string $line): array
    {
        $parts = $line ? explode(' · ', $line) : [];
        $names = $parts ? array_values(array_filter(array_map(fn ($n) => trim(preg_replace('/\s*\+\d+$/', '', $n) ?? ''), explode(', ', $parts[0])))) : [];

        if (count($parts) === 1 && array_diff($names, array_keys(self::PLATFORMS))) {
            return [];
        }

        return $names;
    }

    /** @return list<string> names from a list of strings or of {name} */
    private static function names(mixed $list): array
    {
        return array_values(array_filter(array_map(
            fn ($v) => is_string($v) ? $v : (is_array($v) && is_string($v['name'] ?? null) ? $v['name'] : null),
            is_array($list) ? $list : []
        )));
    }

    /** Marquee marks an outage (down, rate-limited) by wrapping the cause. */
    private static function outage(Throwable $e): bool
    {
        return $e->getPrevious() !== null && is_a($e, self::EXCEPTION);
    }

    /** A Marquee image reference (`/x.jpg`, `igdb:id`, `ol:b:id`, `deezer:album:md5`) as a URL. */
    public static function image(?string $path): ?string
    {
        return match (true) {
            ! $path => null,
            (bool) preg_match('#^/[A-Za-z0-9_-]+\.(jpg|png)$#', $path) => 'https://image.tmdb.org/t/p/w780'.$path,
            (bool) preg_match('/^igdb:([a-z0-9]{1,40})$/', $path, $m) => "https://images.igdb.com/igdb/image/upload/t_cover_big_2x/{$m[1]}.jpg",
            (bool) preg_match('/^ol:b:(\d{1,12})$/', $path, $m) => "https://covers.openlibrary.org/b/id/{$m[1]}-L.jpg",
            (bool) preg_match('/^deezer:album:([0-9a-f]{32})$/', $path, $m) => "https://cdn-images.dzcdn.net/images/cover/{$m[1]}/1000x1000-000000-80-0-0.jpg",
            default => null,
        };
    }
}
