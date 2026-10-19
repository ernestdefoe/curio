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

    /** Main games, standalone expansions, remakes, remasters, expanded games and ports: no DLC, bundles or mods. */
    private const GAME_TYPES = '(0,4,8,9,10,11)';

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

        return $this->cache->remember('curio.igdb.search.'.md5(mb_strtolower($query)), 86400, function () use ($query) {
            // IGDB ranks hits by text alone: take 30 and put the well-known ones first.
            $games = $this->query('games', 'search "'.$query.'"; fields name,first_release_date,total_rating_count,cover.image_id,'
                .'involved_companies.company.name,involved_companies.developer; where game_type = '.self::GAME_TYPES.' & version_parent = null; limit 30;');

            $exact = mb_strtolower($query);
            usort($games, fn ($a, $b) => [mb_strtolower((string) ($a['name'] ?? '')) !== $exact, -($a['total_rating_count'] ?? 0)]
                <=> [mb_strtolower((string) ($b['name'] ?? '')) !== $exact, -($b['total_rating_count'] ?? 0)]);

            return array_map(fn (array $g) => [
                'id' => (string) $g['id'],
                'title' => (string) $g['name'],
                'creator' => self::companies($g, 'developer')[0] ?? null,
                'year' => isset($g['first_release_date']) ? (int) gmdate('Y', (int) $g['first_release_date']) : null,
                'cover' => self::cover($g['cover']['image_id'] ?? null),
            ], array_slice(array_values(array_filter($games, fn ($g) => is_array($g) && isset($g['id'], $g['name']))), 0, 8));
        });
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
