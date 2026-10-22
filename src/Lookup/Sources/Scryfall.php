<?php

namespace Ernestdefoe\Curio\Lookup\Sources;

use Ernestdefoe\Curio\Lookup\Budget;
use Ernestdefoe\Curio\Lookup\CardLanguages;
use Ernestdefoe\Curio\Lookup\CardQuery;
use Ernestdefoe\Curio\Lookup\Http;
use Ernestdefoe\Curio\Lookup\LookupException;
use Ernestdefoe\Curio\Lookup\Paged;
use Ernestdefoe\Curio\Lookup\Source;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Magic: The Gathering cards from Scryfall: free, no key. Scryfall asks for
 * a User-Agent and an Accept header (Http sends both), at most 10 requests a
 * second (8 here), and for answers to be kept at least a day (they are).
 * Each result is one printing. A card language picked in the look-up adds
 * Scryfall's own `lang:` ("稲妻" or "Lightning Bolt" with Japanese finds the
 * Japanese printings); with none, Scryfall answers in English.
 */
class Scryfall implements Source, Paged, CardLanguages
{
    private const BASE = 'https://api.scryfall.com/';

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /** Scryfall's printed languages, by the codes TCGdex and the look-up use ("zh-cn" is Scryfall's "zhs"). */
    public const LANGUAGES = ['en' => 'en', 'es' => 'es', 'es-mx' => 'es', 'fr' => 'fr', 'de' => 'de', 'it' => 'it', 'pt' => 'pt', 'pt-br' => 'pt', 'pt-pt' => 'pt',
        'ja' => 'ja', 'ko' => 'ko', 'ru' => 'ru', 'zh-cn' => 'zhs', 'zh-tw' => 'zht', 'zhs' => 'zhs', 'zht' => 'zht'];

    /** Scryfall's code of the language picked in the look-up; '' for one Magic was never printed in. */
    private ?string $lang = null;

    public function __construct(private Http $http, private Budget $budget, private Cache $cache)
    {
    }

    public function key(): string
    {
        return 'scryfall';
    }

    public function inLanguage(?string $lang): static
    {
        $source = clone $this;
        $source->lang = $lang === null ? null : (self::LANGUAGES[$lang] ?? (in_array($lang, TcgDex::LANGUAGES, true) ? '' : null));

        return $source;
    }

    public function search(string $query): array
    {
        return $this->page($query, 1)[0];
    }

    /**
     * "Lightning Bolt", "Lightning Bolt 117", "Lightning Bolt 2x2 117": a
     * name, then optionally the set code and the collector number, which
     * become Scryfall's own `set:` and `cn:`. A query already written in
     * Scryfall's syntax ("t:goblin set:m21") is passed as it is. Each
     * reading's printings (up to Scryfall's first 175) are kept a day and
     * paged here.
     */
    public function page(string $query, int $page): array
    {
        $query = trim($query);
        $cards = [];

        if ($this->lang === '') {
            // No Magic card was printed in Thai or Indonesian.
            return [[], false];
        }
        $lang = $this->lang !== null ? " lang:$this->lang" : '';

        if (str_contains($query, ':')) {
            $cards = $this->found($query.(str_contains($query, 'lang:') ? '' : $lang));
        } else {
            foreach (array_slice(CardQuery::parse($query)->attempts(), 0, 4) as [$name, $set, $number]) {
                // A set code is one word.
                if ($set !== null && (str_contains($set, ' ') || ! preg_match('/^[a-z0-9]{2,6}$/i', $set))) {
                    continue;
                }
                $cards = $this->found(implode(' ', array_filter([$name, $set !== null ? "set:$set" : null, $number !== null ? "cn:$number" : null])).$lang);
                if ($cards) {
                    // The card itself before others with the name in theirs ("稲妻" before "弧状の稲妻").
                    $exact = mb_strtolower($name);
                    usort($cards, fn ($a, $b) => (mb_strtolower($a['title']) !== $exact && mb_strtolower($a['name']) !== $exact) <=> (mb_strtolower($b['title']) !== $exact && mb_strtolower($b['name']) !== $exact));
                    break;
                }
            }
        }

        return [array_slice($cards, ($page - 1) * self::PER_PAGE, self::PER_PAGE), count($cards) > $page * self::PER_PAGE];
    }

    /** @return list<array<string, mixed>> the printings Scryfall finds for this query, newest first */
    private function found(string $q): array
    {
        return $this->cache->remember('curio.scryfall.found.2.'.md5(mb_strtolower($q)), 86400, function () use ($q) {
            try {
                $cards = $this->get('cards/search', ['q' => $q, 'unique' => 'prints', 'order' => 'released'])['data'] ?? [];
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

            return array_values(array_map(fn (array $c) => [
                'id' => $c['id'],
                'title' => self::printedName($c),
                'name' => $c['name'],
                'creator' => null,
                'year' => self::year($c),
                'cover' => self::image($c, 'small'),
                'set' => is_string($c['set_name'] ?? null) ? $c['set_name'].(is_string($c['set'] ?? null) ? ' ('.strtoupper($c['set']).')' : '') : null,
                'number' => is_string($c['collector_number'] ?? null) ? $c['collector_number'] : null,
            ], $cards));
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
            'title' => self::printedName($c),
            // The card's artist; its set is a detail of its own.
            'creator' => is_string($c['artist'] ?? null) && trim($c['artist']) !== '' ? $c['artist'] : null,
            'year' => self::year($c),
            'cover' => self::image($c, 'large'),
            'wikidata' => null,
            'facts' => ['card' => [
                'set' => $set,
                'number' => is_string($c['collector_number'] ?? null) ? $c['collector_number'] : null,
                'rarity' => is_string($c['rarity'] ?? null) ? ucfirst($c['rarity']) : null,
                // Scryfall's "zhs"/"zht" as language tags every browser can name.
                'language' => is_string($c['lang'] ?? null) ? (['zhs' => 'zh-Hans', 'zht' => 'zh-Hant'][$c['lang']] ?? $c['lang']) : null,
                // Its finishes as Variant choices: non-foil is the normal card.
                'variants' => array_values(array_intersect_key(['nonfoil' => 'normal', 'foil' => 'foil', 'etched' => 'etched'], array_flip(array_filter((array) ($c['finishes'] ?? []), 'is_string')))),
            ]],
        ];
    }

    /** The name printed on this card: "稲妻" on a Japanese Lightning Bolt. */
    private static function printedName(array $c): string
    {
        $printed = $c['printed_name'] ?? $c['card_faces'][0]['printed_name'] ?? null;

        return is_string($printed) && trim($printed) !== '' && ($c['lang'] ?? 'en') !== 'en' ? $printed : $c['name'];
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
