<?php

namespace Ernestdefoe\Curio\Lookup\Sources;

use Ernestdefoe\Curio\Lookup\Budget;
use Ernestdefoe\Curio\Lookup\CardQuery;
use Ernestdefoe\Curio\Lookup\Http;
use Ernestdefoe\Curio\Lookup\LookupException;
use Ernestdefoe\Curio\Lookup\Paged;
use Ernestdefoe\Curio\Lookup\Source;
use Ernestdefoe\Curio\Translation\WorkTexts;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Pokémon cards from TCGdex (tcgdex.dev): free, no key, in the reader's
 * language where TCGdex has it (names, sets and rarities in French, German…).
 * Each result is one printing: its set and number tell them apart.
 */
class TcgDex implements Source, Paged
{
    private const BASE = 'https://api.tcgdex.net/v2/';

    /** A name's cards fetched at once ("Pikachu" has some 250). */
    private const MAX_CARDS = 400;

    /** Set pages fetched in one search to learn their codes. */
    private const MAX_SET_PAGES = 40;

    /** @var array<string, list<array<string, mixed>>> names already fetched in this request */
    private array $found = [];

    private const LANGUAGES = ['en', 'fr', 'es', 'it', 'pt', 'de', 'nl', 'pl', 'ru', 'ja', 'ko', 'id', 'th'];

    public function __construct(private Http $http, private Budget $budget, private Cache $cache, private TranslatorInterface $translator)
    {
    }

    public function key(): string
    {
        return 'tcgdex';
    }

    public function search(string $query): array
    {
        return $this->page($query, 1)[0];
    }

    /**
     * "Goomy", "Goomy 195", "Goomy 195/264", "Goomy fst 195", "Goomy Fusion
     * Strike": a name, then optionally the set (its code, id or name) and
     * the card's number. Every card of a name is fetched once and kept a
     * day; the set and number are picked out of it here, so paging and
     * narrowing cost nothing more.
     */
    public function page(string $query, int $page): array
    {
        $asked = CardQuery::parse($query);
        $cards = [];

        // In the reader's language, then in English: a French reader may know "Goomy" better than "Mucuscule".
        foreach (array_unique([$this->language(), 'en']) as $lang) {
            if ($cards = $this->find($lang, $asked)) {
                break;
            }
        }

        $shown = array_slice($cards, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        return [array_map(fn (array $c) => [
            'id' => "$lang/{$c['id']}",
            'title' => $c['name'],
            'creator' => null,
            'year' => null,
            'cover' => self::image($c['image'], 'low'),
            'set' => $c['set'],
            'number' => self::number($c['localId'], $c['official']),
        ], $shown), count($cards) > $page * self::PER_PAGE];
    }

    public function details(string $id): array
    {
        if (! preg_match('#^([a-z]{2})/([A-Za-z0-9.-]{1,40})$#', $id, $m) || ! in_array($m[1], self::LANGUAGES, true)) {
            throw new LookupException("Not a TCGdex id: $id");
        }

        $c = $this->get("{$m[1]}/cards/{$m[2]}");

        if (! is_string($c['name'] ?? null) || ! is_string($c['localId'] ?? null)) {
            throw new LookupException("TCGdex has no card $id");
        }

        $set = is_string($c['set']['name'] ?? null) ? $c['set']['name'] : null;
        // "None" (and its translations, "Sans Rareté"…) is no rarity at all.
        $rarity = is_string($c['rarity'] ?? null) && ! preg_match('/^(none|sans raret[ée]|ohne seltenheit|sin rareza|nessuna rarità|sem raridade)$/iu', $c['rarity']) ? $c['rarity'] : null;

        return [
            'title' => $c['name'],
            // The card's artist; its set is a detail of its own.
            'creator' => is_string($c['illustrator'] ?? null) && trim($c['illustrator']) !== '' ? $c['illustrator'] : null,
            'year' => null,
            'cover' => self::image($c['image'] ?? null, 'high'),
            'wikidata' => null,
            'facts' => ['card' => [
                'set' => $set,
                'number' => self::number($c['localId'], $c['set']['cardCount']['official'] ?? null),
                'rarity' => $rarity,
                'language' => $m[1],
                'variants' => self::variants($c['variants'] ?? null),
            ]],
        ];
    }

    /**
     * The cards of the first reading of the query that finds any.
     *
     * @return list<array<string, mixed>>
     */
    private function find(string $lang, CardQuery $asked): array
    {
        foreach (array_slice($asked->attempts(), 0, 6) as [$name, $set, $number]) {
            $cards = $this->named($lang, $name);
            if ($set !== null) {
                $cards = $this->inSet($cards, $set);
            }
            if ($number !== null) {
                $cards = array_values(array_filter($cards, fn ($c) => CardQuery::sameNumber($c['localId'], $number)
                    && ($asked->total === null || $c['official'] === null || CardQuery::sameNumber((string) $c['official'], $asked->total))));
            }
            if ($cards) {
                return $cards;
            }
        }

        return [];
    }

    /**
     * Every card whose name holds these words (TCGdex's own name match), app-only
     * TCG Pocket cards left out, the name itself before longer ones ("Pikachu"
     * before "Pikachu ex"), pictured ones first. Kept a day.
     *
     * @return list<array{id: string, localId: string, name: string, image: ?string, setId: string, set: ?string, official: ?int}>
     */
    private function named(string $lang, string $name): array
    {
        return $this->found[$lang.$name] ??= $this->cache->remember("curio.tcgdex.named.$lang.".md5(mb_strtolower($name)), 86400, function () use ($lang, $name) {
            $cards = array_values(array_filter(
                $this->get("$lang/cards", ['name' => $name, 'pagination:itemsPerPage' => self::MAX_CARDS]),
                fn ($c) => is_array($c) && is_string($c['id'] ?? null) && is_string($c['name'] ?? null) && is_string($c['localId'] ?? null)
            ));
            $sets = $cards ? $this->sets($lang) : ['sets' => [], 'digital' => []];

            $cards = array_values(array_filter(array_map(function (array $c) use ($sets) {
                $setId = self::setId($c);
                $set = $sets['sets'][$setId] ?? null;

                return in_array($setId, $sets['digital'], true) ? null : [
                    'id' => $c['id'], 'localId' => $c['localId'], 'name' => $c['name'], 'image' => is_string($c['image'] ?? null) ? $c['image'] : null,
                    'setId' => $setId, 'set' => $set['name'] ?? null, 'official' => $set['official'] ?? null,
                ];
            }, $cards)));

            $exact = mb_strtolower($name);
            usort($cards, fn ($a, $b) => [mb_strtolower($a['name']) !== $exact, $a['image'] === null] <=> [mb_strtolower($b['name']) !== $exact, $b['image'] === null]);

            return $cards;
        });
    }

    /**
     * The cards from the set these words name: its code as printed on the card
     * ("FST"), TCGdex's id ("swsh8") or its name ("Fusion Strike", "fusion").
     *
     * @param list<array<string, mixed>> $cards
     * @return list<array<string, mixed>>
     */
    private function inSet(array $cards, string $words): array
    {
        $asked = CardQuery::plain($words);
        if ($asked === '' || ! $cards) {
            return [];
        }

        $byName = array_values(array_filter($cards, fn ($c) => strtolower($c['setId']) === $asked
            || (mb_strlen($asked) >= 3 && $c['set'] !== null && str_contains(CardQuery::plain($c['set']), $asked))));

        // A set code is short and has letters ("FST"); "195264" is not one.
        if ($byName || mb_strlen($asked) > 6 || ! preg_match('/\p{L}/u', $asked)) {
            return $byName;
        }

        $codes = $this->codes(array_values(array_unique(array_column($cards, 'setId'))));

        return array_values(array_filter($cards, fn ($c) => ($codes[$c['setId']] ?? null) === $asked));
    }

    /**
     * Each set's code as printed on its cards ("FST" for Fusion Strike), lower
     * case. TCGdex lists most in one GraphQL answer; newer sets only say it
     * on their own page, fetched once a month and at most MAX_SET_PAGES at a
     * time.
     *
     * @param list<string> $setIds
     * @return array<string, string>
     */
    private function codes(array $setIds): array
    {
        $listed = $this->cache->remember('curio.tcgdex.codes', 7 * 86400, function () {
            $this->budget->spend('tcgdex', 10, 5000);
            $sets = $this->http->post('tcgdex', self::BASE.'graphql', [], ['Content-Type' => 'application/json'], (string) json_encode(['query' => '{ sets { id tcgOnline } }']))['data']['sets'] ?? [];
            $codes = [];
            foreach ((array) $sets as $s) {
                if (is_string($s['id'] ?? null) && is_string($s['tcgOnline'] ?? null) && $s['tcgOnline'] !== '') {
                    $codes[$s['id']] = strtolower($s['tcgOnline']);
                }
            }

            return $codes;
        });

        $codes = [];
        $fetched = 0;
        foreach ($setIds as $id) {
            if (isset($listed[$id])) {
                $codes[$id] = $listed[$id];
                continue;
            }
            $key = 'curio.tcgdex.code.'.md5($id);
            $code = $this->cache->get($key);
            if ($code === null && $fetched < self::MAX_SET_PAGES && preg_match('/^[A-Za-z0-9.-]{1,40}$/', $id)) {
                $fetched++;
                $abbreviation = $this->get("en/sets/$id")['abbreviation']['official'] ?? null;
                $code = is_string($abbreviation) ? strtolower($abbreviation) : '';
                $this->cache->put($key, $code, 30 * 86400);
            }
            if (is_string($code) && $code !== '') {
                $codes[$id] = $code;
            }
        }

        return $codes;
    }

    /**
     * Each set by id, and the ids of the app-only (TCG Pocket) sets; kept a day.
     *
     * @return array{sets: array<string, array{name: string, official: ?int}>, digital: list<string>}
     */
    private function sets(string $lang): array
    {
        return $this->cache->remember("curio.tcgdex.sets.2.$lang", 86400, function () use ($lang) {
            $sets = [];
            foreach ($this->get("$lang/sets") as $s) {
                if (is_array($s) && is_string($s['id'] ?? null) && is_string($s['name'] ?? null)) {
                    $sets[$s['id']] = ['name' => $s['name'], 'official' => is_int($s['cardCount']['official'] ?? null) ? $s['cardCount']['official'] : null];
                }
            }

            $digital = array_values(array_filter(array_column((array) ($this->get("$lang/series/tcgp")['sets'] ?? []), 'id'), 'is_string'));

            return ['sets' => $sets, 'digital' => $digital];
        });
    }

    /** The finishes this card was printed in, as Variant choices ("normal", "reverse", "holo"…). */
    private static function variants(mixed $variants): array
    {
        $keys = ['normal' => 'normal', 'reverse' => 'reverse', 'holo' => 'holo', 'firstEdition' => 'first_edition', 'wPromo' => 'w_promo'];

        return is_array($variants) ? array_values(array_intersect_key($keys, array_filter($variants, fn ($v) => $v === true))) : [];
    }

    /** "swsh3-136" with local id "136" is card 136 of set "swsh3". */
    private static function setId(array $card): string
    {
        $suffix = '-'.$card['localId'];

        return str_ends_with($card['id'], $suffix) ? substr($card['id'], 0, -strlen($suffix)) : '';
    }

    /** "136/189", as printed on the card. */
    private static function number(string $local, mixed $official): string
    {
        return is_int($official) && $official > 0 && ctype_digit($local) ? "$local/$official" : $local;
    }

    private static function image(mixed $base, string $quality): ?string
    {
        return is_string($base) && preg_match('#^https://assets\.tcgdex\.net/[A-Za-z0-9./_-]{1,120}$#', $base) ? "$base/$quality.webp" : null;
    }

    private function language(): string
    {
        $lang = WorkTexts::base((string) $this->translator->getLocale());

        return in_array($lang, self::LANGUAGES, true) ? $lang : 'en';
    }

    /** @return array<mixed> */
    private function get(string $path, array $query = []): array
    {
        $this->budget->spend('tcgdex', 10, 5000);

        return $this->http->json('tcgdex', self::BASE.$path, $query);
    }
}
