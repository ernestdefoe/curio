<?php

namespace Ernestdefoe\Curio\Lookup\Sources;

use Ernestdefoe\Curio\Lookup\Budget;
use Ernestdefoe\Curio\Lookup\Http;
use Ernestdefoe\Curio\Lookup\LookupException;
use Ernestdefoe\Curio\Lookup\Source;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Magic: The Gathering cards from Scryfall: free, no key. Scryfall asks for
 * a User-Agent and an Accept header (Http sends both), at most 10 requests a
 * second (8 here), and for answers to be kept at least a day (they are).
 * Each result is one printing.
 */
class Scryfall implements Source
{
    private const BASE = 'https://api.scryfall.com/';

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(private Http $http, private Budget $budget, private Cache $cache)
    {
    }

    public function key(): string
    {
        return 'scryfall';
    }

    public function search(string $query): array
    {
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        return $this->cache->remember('curio.scryfall.search.'.md5(mb_strtolower($query)), 86400, function () use ($query) {
            try {
                $cards = $this->get('cards/search', ['q' => $query, 'unique' => 'prints', 'order' => 'released'])['data'] ?? [];
            } catch (LookupException $e) {
                // A query Scryfall cannot read (400) finds nothing; an outage is still an outage.
                if ($e->outage) {
                    throw $e;
                }
                $cards = [];
            }

            // Printings not out yet (announced sets) are left out: nobody holds one.
            $today = gmdate('Y-m-d');
            $cards = array_filter((array) $cards, fn ($c) => is_array($c) && is_string($c['id'] ?? null) && preg_match(self::UUID, $c['id']) && is_string($c['name'] ?? null)
                && (string) ($c['released_at'] ?? '') <= $today);

            return array_map(fn (array $c) => [
                'id' => $c['id'],
                'title' => $c['name'],
                'creator' => is_string($c['set_name'] ?? null) ? $c['set_name'] : null,
                'year' => self::year($c),
                'cover' => self::image($c, 'small'),
                'number' => is_string($c['collector_number'] ?? null) ? $c['collector_number'] : null,
            ], array_slice(array_values($cards), 0, 8));
        });
    }

    public function details(string $id): array
    {
        if (! preg_match(self::UUID, $id)) {
            throw new LookupException("Not a Scryfall id: $id");
        }

        $c = $this->get("cards/$id");

        if (! is_string($c['name'] ?? null)) {
            throw new LookupException("Scryfall has no card $id");
        }

        $set = is_string($c['set_name'] ?? null) ? $c['set_name'] : null;

        return [
            'title' => $c['name'],
            'creator' => $set,
            'year' => self::year($c),
            'cover' => self::image($c, 'large'),
            'wikidata' => null,
            'facts' => ['card' => [
                'set' => $set,
                'number' => is_string($c['collector_number'] ?? null) ? $c['collector_number'] : null,
                'rarity' => is_string($c['rarity'] ?? null) ? ucfirst($c['rarity']) : null,
                'language' => is_string($c['lang'] ?? null) ? $c['lang'] : null,
            ]],
        ];
    }

    private static function year(array $c): ?int
    {
        return is_string($c['released_at'] ?? null) && preg_match('/^(\d{4})-/', $c['released_at'], $m) ? (int) $m[1] : null;
    }

    /** The card's picture (the front face of a double-faced card). */
    private static function image(array $c, string $size): ?string
    {
        $url = $c['image_uris'][$size] ?? $c['card_faces'][0]['image_uris'][$size] ?? null;

        return is_string($url) && str_starts_with($url, 'https://cards.scryfall.io/') ? $url : null;
    }

    /** @return array<mixed> */
    private function get(string $path, array $query = []): array
    {
        $this->budget->spend('scryfall', 8, 5000);

        return $this->http->json('scryfall', self::BASE.$path, $query);
    }
}
