<?php

namespace Ernestdefoe\Curio\Lookup\Sources;

use Ernestdefoe\Curio\Lookup\Http;
use Ernestdefoe\Curio\Lookup\LookupException;
use Ernestdefoe\Curio\Lookup\Source;
use Ernestdefoe\Curio\Settings;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Games from IGDB with the forum's own Twitch app (client id + secret). A
 * Twitch app token lasts about 60 days and is kept until a day before it ends;
 * searches are kept for a day; IGDB's 4 requests a second is never exceeded,
 * and the forum sends at most 2,000 a day.
 */
class Igdb implements Source
{
    private const TOKEN_URL = 'https://id.twitch.tv/oauth2/token';

    private const BASE = 'https://api.igdb.com/v4/';

    private const PER_SECOND = 4;

    private const PER_DAY = 2000;

    /**
     * dlc, expansion, bundle…; edition for a main game filed under another; null for a game. Public for tests.
     *
     * @param array<string, mixed> $game
     */
    public static function release(array $game): ?string
    {
        return self::RELEASES[$game['game_type'] ?? 0] ?? (isset($game['version_parent']) ? 'edition' : null);
    }

    /**
     * Main games, DLC, expansions, bundles, standalone expansions, remakes,
     * remasters, expanded games and ports: no packs or mods. Expansions and
     * story DLC (Shadow of the Erdtree) are collected too (Wlork, Discuss
     * d/40077 #77). Bundles are in because IGDB files real games as bundles
     * too (UFO 50, Collection of SaGa, Mass Effect Legendary Edition); rank()
     * keeps store bundles and unrated DLC under the games.
     */
    public const GAME_TYPES = '(0,1,2,3,4,8,9,10,11)';

    /**
     * Editions of another game are searched too: IGDB files some real releases
     * as an edition, such as "Shovel Knight: Treasure Trove" and "Romancing
     * SaGa: Minstrel Song Remastered International Special Edition" (Wlork,
     * Discuss d/40107 #73, #83); rank() keeps them under their game.
     */
    public const WHERE = 'where game_type = '.self::GAME_TYPES.';';

    private const DLC = 1;

    /** What a result is when it isn't a main game, so versions can be told apart (Wlork, Discuss d/40077 #80). */
    private const RELEASES = [1 => 'dlc', 2 => 'expansion', 3 => 'bundle', 4 => 'standalone', 8 => 'remake', 9 => 'remaster', 10 => 'expanded', 11 => 'port'];

    private const BUNDLE = 3;

    public function __construct(private Http $http, private SettingsRepositoryInterface $settings, private Cache $cache)
    {
    }

    public function configured(): bool
    {
        return $this->clientId() !== '' && trim((string) $this->settings->get(Settings::IGDB_CLIENT_SECRET)) !== '';
    }

    public function key(): string
    {
        return 'igdb';
    }

    public function search(string $query): array
    {
        $query = trim(str_replace(['\\', '"'], ' ', $query));

        if ($query === '') {
            return [];
        }

        return $this->cache->remember('curio.igdb.search.8.'.md5(mb_strtolower($query)), 86400, function () use ($query) {
            $fields = 'fields name,game_type,version_parent,first_release_date,total_rating_count,cover.image_id,'
                .'platforms.name,platforms.abbreviation,involved_companies.company.name,involved_companies.developer;';

            // IGDB ranks hits by text alone: take 50 and put the well-known ones first.
            $games = $this->query('games', 'search "'.$query.'"; '.$fields.' '.self::WHERE.' limit 50;');

            // IGDB's search stems some titles to nothing like them: "Going Under"
            // comes back as fifty "Go! Go!" games and never the game itself
            // (Wlork, Discuss d/40107 #91). When nothing found is the title
            // typed, the titles that contain it are asked for too.
            $exact = self::plain($query);
            if (! array_filter($games, fn ($g) => is_array($g) && self::plain((string) ($g['name'] ?? '')) === $exact)) {
                $named = $this->query('games', $fields.' where name ~ *"'.$query.'"* & game_type = '.self::GAME_TYPES.'; limit 20;');
                $seen = array_column($games, 'id');
                foreach ($named as $g) {
                    if (is_array($g) && isset($g['id']) && ! in_array($g['id'], $seen, true)) {
                        $games[] = $g;
                    }
                }
            }

            return array_map(fn (array $g) => [
                'id' => (string) $g['id'],
                'title' => (string) $g['name'],
                'creator' => self::companies($g, 'developer')[0] ?? null,
                'release' => self::release($g),
                'year' => isset($g['first_release_date']) ? (int) gmdate('Y', (int) $g['first_release_date']) : null,
                'cover' => self::cover($g['cover']['image_id'] ?? null),
                // Which machines it came out on, so the right copy can be picked.
                'variants' => self::platforms($g),
            ], array_slice(self::rank($games, $query), 0, 8));
        });
    }

    /**
     * The exact title first (punctuation aside), then the well-known (most
     * rated) first. A bundle nobody has rated ("Halo 3 + Halo Wars", "Dark
     * Souls Trilogy Box") is a shop listing, not a game people know, so it
     * comes after every game; a rated one (UFO 50, Mass Effect Trilogy) ranks
     * like any game. An edition (Deluxe, Collector's, GOTY) never comes before
     * the game it is an edition of. Public so it is tested without credentials.
     *
     * @param list<mixed> $games
     * @return list<array<string, mixed>>
     */
    public static function rank(array $games, string $query): array
    {
        $games = array_values(array_filter($games, fn ($g) => is_array($g) && isset($g['id'], $g['name'])));
        $exact = self::plain($query);
        $ratings = array_combine(array_column($games, 'id'), array_map(fn ($g) => (int) ($g['total_rating_count'] ?? 0), $games));
        $key = function (array $g) use ($exact, $ratings) {
            $count = (int) ($g['total_rating_count'] ?? 0);
            $parent = $g['version_parent'] ?? null;
            $edition = $parent !== null && self::plain((string) $g['name']) !== $exact;

            return [
                self::plain((string) $g['name']) !== $exact,
                in_array($g['game_type'] ?? null, [self::DLC, self::BUNDLE], true) && $count === 0,
                // An edition ranks by its own ratings, but below its game.
                -($edition && array_key_exists($parent, $ratings) ? min($count, $ratings[$parent]) : $count),
                $edition,
            ];
        };
        usort($games, fn ($a, $b) => $key($a) <=> $key($b));

        return $games;
    }

    /** "Romancing SaGa: Minstrel Song" and "romancing saga minstrel song" are the same title. */
    private static function plain(string $title): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($title));
    }

    public function details(string $id): array
    {
        if (! ctype_digit($id) || strlen($id) > 12) {
            throw new LookupException("Not an IGDB id: $id");
        }

        $g = $this->query('games', 'fields name,slug,summary,first_release_date,cover.image_id,platforms.name,genres.name,'
            ."involved_companies.company.name,involved_companies.developer,involved_companies.publisher; where id = $id;")[0] ?? null;

        if (! is_array($g) || empty($g['name'])) {
            throw new LookupException("IGDB has no game $id");
        }

        return self::normalise($g);
    }

    /**
     * An IGDB game as a look-up result. Public so it is tested without credentials.
     *
     * @param array<string, mixed> $g
     */
    public static function normalise(array $g): array
    {
        $date = isset($g['first_release_date']) ? gmdate('Y-m-d', (int) $g['first_release_date']) : null;
        $developers = self::companies($g, 'developer');

        return [
            'title' => (string) $g['name'],
            'creator' => $developers[0] ?? null,
            'year' => $date ? (int) substr($date, 0, 4) : null,
            'cover' => self::cover($g['cover']['image_id'] ?? null),
            // Wikidata knows games by their IGDB slug (P5794).
            'wikidata' => is_string($g['slug'] ?? null) && preg_match('/^[a-z0-9-]{1,100}$/', $g['slug']) ? ['P5794', $g['slug']] : null,
            'description' => is_string($g['summary'] ?? null) ? $g['summary'] : null,
            'facts' => [
                'developer' => $developers,
                'publisher' => self::companies($g, 'publisher'),
                'released' => $date,
                'platforms' => array_column(array_filter((array) ($g['platforms'] ?? []), 'is_array'), 'name'),
                'genres' => array_column(array_filter((array) ($g['genres'] ?? []), 'is_array'), 'name'),
            ],
        ];
    }

    /**
     * Current machines first (IGDB lists them by id: Linux before PC), each as
     * a choice: its short name on the chip, its full name to match the type's
     * platform options.
     *
     * @return list<array{label: string, fields: array{platform: string}}>
     */
    public static function platforms(array $g): array
    {
        $platforms = array_values(array_filter((array) ($g['platforms'] ?? []), fn ($p) => is_array($p) && is_string($p['name'] ?? null)));
        $rank = fn (array $p) => ($r = array_search($p['id'] ?? null, self::PLATFORM_ORDER, true)) === false ? 100 : $r;
        usort($platforms, fn ($a, $b) => $rank($a) <=> $rank($b));

        return array_map(fn (array $p) => [
            'label' => mb_strlen($p['name']) > 18 && is_string($p['abbreviation'] ?? null) ? $p['abbreviation'] : $p['name'],
            'fields' => ['platform' => $p['name']],
        ], array_slice($platforms, 0, 30));
    }

    /** PC, PS5, PS4, Xbox Series, Xbox One, Switch 2, Switch, Mac, iOS, Android. */
    private const PLATFORM_ORDER = [6, 167, 48, 169, 49, 508, 130, 14, 39, 34];

    /** @return list<string> */
    private static function companies(array $g, string $role): array
    {
        $names = [];
        foreach ((array) ($g['involved_companies'] ?? []) as $c) {
            if (is_array($c) && ! empty($c[$role]) && is_string($c['company']['name'] ?? null)) {
                $names[] = $c['company']['name'];
            }
        }

        return array_values(array_unique($names));
    }

    private static function cover(mixed $imageId): ?string
    {
        return is_string($imageId) && preg_match('/^[a-z0-9]{1,40}$/', $imageId)
            ? "https://images.igdb.com/igdb/image/upload/t_cover_big_2x/$imageId.jpg" : null;
    }

    /**
     * @return list<mixed>
     * @throws LookupException
     */
    private function query(string $endpoint, string $body): array
    {
        if (! $this->configured()) {
            throw new LookupException('IGDB is not set up');
        }

        $day = 'curio.igdb.day.'.date('Ymd');
        $this->cache->add($day, 0, 2 * 86400);
        if ($this->cache->increment($day) > self::PER_DAY) {
            throw new LookupException('IGDB daily allowance spent', true);
        }

        try {
            return $this->send($endpoint, $body);
        } catch (LookupException $e) {
            if (! $e->unauthorized) {
                throw $e;
            }
            // A revoked or expired token: one fresh one, one more try.
            $this->cache->forget($this->tokenKey());

            return $this->send($endpoint, $body);
        }
    }

    /** @return list<mixed> */
    private function send(string $endpoint, string $body): array
    {
        $this->pace();

        return array_values($this->http->post('igdb', self::BASE.$endpoint, [], [
            'Client-ID' => $this->clientId(),
            'Authorization' => 'Bearer '.$this->token(),
        ], $body));
    }

    private function token(): string
    {
        if ($token = $this->cache->get($this->tokenKey())) {
            return (string) $token;
        }

        $data = $this->http->post('twitch', self::TOKEN_URL, [
            'client_id' => $this->clientId(),
            'client_secret' => trim((string) $this->settings->get(Settings::IGDB_CLIENT_SECRET)),
            'grant_type' => 'client_credentials',
        ]);

        if (! is_string($data['access_token'] ?? null) || $data['access_token'] === '') {
            throw new LookupException('Twitch issued no IGDB token');
        }

        // Kept until a day before it ends (half its life when it is short).
        $ttl = (int) ($data['expires_in'] ?? 3600);
        $this->cache->put($this->tokenKey(), $data['access_token'], max(60, $ttl > 172800 ? $ttl - 86400 : intdiv($ttl, 2)));

        return $data['access_token'];
    }

    /** Per app, so changing the credentials starts a new token. */
    private function tokenKey(): string
    {
        return 'curio.igdb.token.'.md5($this->clientId());
    }

    private function clientId(): string
    {
        return trim((string) $this->settings->get(Settings::IGDB_CLIENT_ID));
    }

    /** Wait for the next second when this one has had its 4 requests. */
    private function pace(): void
    {
        $key = 'curio.igdb.rate.'.time();
        $this->cache->add($key, 0, 5);

        if ($this->cache->increment($key) > self::PER_SECOND) {
            usleep((int) ((1 - fmod(microtime(true), 1)) * 1000000) + 20000);
        }
    }
}
