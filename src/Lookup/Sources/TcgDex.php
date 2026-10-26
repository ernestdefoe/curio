<?php

namespace Ernestdefoe\Curio\Lookup\Sources;

use Ernestdefoe\Curio\Lookup\Budget;
use Ernestdefoe\Curio\Lookup\CardLanguages;
use Ernestdefoe\Curio\Lookup\CardQuery;
use Ernestdefoe\Curio\Lookup\Http;
use Ernestdefoe\Curio\Lookup\LookupException;
use Ernestdefoe\Curio\Lookup\Paged;
use Ernestdefoe\Curio\Lookup\Source;
use Ernestdefoe\Curio\Translation\WorkTexts;
use Ernestdefoe\Curio\Type;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Pokémon cards from TCGdex (tcgdex.dev): free, no key, in the reader's
 * language where TCGdex has it (names, sets and rarities in French, German…).
 * Each result is one printing: its set and number tell them apart.
 *
 * The look-up's "Card language" searches one language's own database: the
 * Asian ones (ja, ko, zh-tw, zh-cn, id, th) have their own sets ("SV-P",
 * "SV1S"). A name from another language ("Tatsugiri" for a Japanese card)
 * is found through the Pokédex number its cards carry.
 */
class TcgDex implements Source, Paged, CardLanguages
{
    private const BASE = 'https://api.tcgdex.net/v2/';

    /** A name's cards fetched at once ("Pikachu" has some 250). */
    private const MAX_CARDS = 400;

    /** Set pages fetched in one search to learn their codes. */
    private const MAX_SET_PAGES = 40;

    /** @var array<string, list<array<string, mixed>>> names already fetched in this request */
    private array $found = [];

    /** Every language TCGdex has cards in (its 404 for any other names this list). */
    public const LANGUAGES = ['en', 'fr', 'es', 'es-mx', 'it', 'pt', 'pt-br', 'pt-pt', 'de', 'nl', 'pl', 'ru', 'ja', 'ko', 'zh-tw', 'zh-cn', 'id', 'th'];

    /** Languages with their own sets, whose ids are the codes printed on the cards. */
    private const ASIAN = ['ja', 'ko', 'zh-tw', 'zh-cn', 'id', 'th'];

    /** Finishes TCGdex names a printing by (variants_detailed "type"). */
    private const FINISHES = ['normal', 'holo', 'reverse', 'metal', 'lenticular'];

    /**
     * Words that name a printing, not the card: "Gengar 057/091 trick or
     * trade" is Gengar 057/091, and the Trick or Trade printing is picked
     * from its chips.
     */
    private const PRINTING_WORDS = '/\b(trick[\s-]*or[\s-]*trade|cosmos(\s+holo)?|reverse(\s+holo)?|holo|(1st|first)\s+edition)\b/iu';

    /** The language picked in the look-up, or null for the reader's own. */
    private ?string $lang = null;

    public function __construct(private Http $http, private Budget $budget, private Cache $cache, private TranslatorInterface $translator)
    {
    }

    public function key(): string
    {
        return 'tcgdex';
    }

    public function inLanguage(?string $lang): static
    {
        $source = clone $this;
        $source->lang = in_array($lang, self::LANGUAGES, true) ? $lang : null;

        return $source;
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
        $asked = CardQuery::parse(self::withoutPrinting($query));
        $cards = [];

        if ($this->lang !== null) {
            // A language picked: its cards only, found by any language's name.
            $cards = $this->find($lang = $this->lang, $asked, true);
        } else {
            // In the reader's language, then in English: a French reader may know "Goomy" better than "Mucuscule".
            foreach (array_unique([$this->language(), 'en']) as $lang) {
                if ($cards = $this->find($lang, $asked, false)) {
                    break;
                }
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
            'number' => self::number($c['localId'], $c['official'], $c['setId']),
        ], $shown), count($cards) > $page * self::PER_PAGE];
    }

    public function details(string $id): array
    {
        if (! preg_match('#^([a-z]{2}(?:-[a-z]{2})?)/([A-Za-z0-9.-]{1,40})$#', $id, $m) || ! in_array($m[1], self::LANGUAGES, true)) {
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
                'number' => self::number($c['localId'], $c['set']['cardCount']['official'] ?? null, is_string($c['set']['id'] ?? null) ? $c['set']['id'] : ''),
                'rarity' => $rarity,
                'language' => $m[1],
                'variants' => self::variants($c['variants'] ?? null),
                'printings' => $this->printings($c, $m[1], $m[2]),
            ]],
        ];
    }

    /**
     * The cards of the first reading of the query that finds any.
     *
     * @return list<array<string, mixed>>
     */
    private function find(string $lang, CardQuery $asked, bool $anyLanguage): array
    {
        foreach (array_slice($asked->attempts(), 0, 8) as [$name, $set, $number]) {
            if ($name === '') {
                // "SV-P 201", "201/SV-P": a set and a number, no name.
                $cards = $this->setCards($lang, (string) $set);
            } else {
                $cards = $this->named($lang, $name);
                if (! $cards && $anyLanguage) {
                    $cards = $this->sameSpecies($lang, $name);
                }
                if ($set !== null) {
                    $cards = $this->inSet($lang, $cards, $set);
                }
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
        $exact = mb_strtolower($name);

        return $this->listed($lang, 'named.'.md5($exact), ['name' => $name], fn ($c) => [mb_strtolower($c['name']) !== $exact, $c['image'] === null]);
    }

    /**
     * The cards in this language of the Pokémon a name from another language
     * means: "Tatsugiri" → Pokédex 978 → シャリタツ. The name is looked for in
     * English, the reader's language and the language its script points to;
     * the species is kept a month. Trainers and energies have no number, so
     * they are found by their name in the card's own language only.
     *
     * @return list<array<string, mixed>>
     */
    private function sameSpecies(string $lang, string $name): array
    {
        if (str_contains($name, '/') || mb_strlen($name) > 40) {
            return [];
        }

        $script = match (true) {
            (bool) preg_match('/[\p{Hiragana}\p{Katakana}]/u', $name) => 'ja',
            (bool) preg_match('/\p{Hangul}/u', $name) => 'ko',
            (bool) preg_match('/\p{Thai}/u', $name) => 'th',
            (bool) preg_match('/\p{Han}/u', $name) => 'zh-tw',
            default => null,
        };
        $from = array_diff(array_unique(array_filter($script ? [$script] : ['en', $this->language()])), [$lang]);

        foreach ($from as $other) {
            if ($dex = $this->species($other, $name)) {
                // Shortest names first: シャリタツ before シャリタツex.
                return $this->listed($lang, "dex.$dex", ['dexId' => "eq:$dex"], fn ($c) => [mb_strlen($c['name']), $c['image'] === null]);
            }
        }

        return [];
    }

    /** The Pokédex number of the Pokémon this name (in that language) means, or null; kept a month (a miss, a day). */
    private function species(string $lang, string $name): ?int
    {
        $key = "curio.tcgdex.dex.$lang.".md5(mb_strtolower($name));
        $dex = $this->cache->get($key);

        if (! is_int($dex)) {
            $exact = mb_strtolower($name);
            $named = array_values(array_filter(
                $this->get("$lang/cards", ['name' => $name, 'pagination:itemsPerPage' => 50]),
                fn ($c) => is_array($c) && is_string($c['id'] ?? null) && is_string($c['name'] ?? null)
                    && str_starts_with(mb_strtolower($c['name']), $exact) && preg_match('/^[A-Za-z0-9.-]{1,40}$/', $c['id'])
            ));
            // The name itself, else one that starts with it ("Tatsugiri ex"); promos and old cards may carry no number.
            usort($named, fn ($a, $b) => (mb_strtolower($a['name']) !== $exact) <=> (mb_strtolower($b['name']) !== $exact));
            $dex = 0;
            foreach (array_slice($named, 0, 3) as $card) {
                $ids = $this->get("$lang/cards/{$card['id']}")['dexId'] ?? null;
                if (is_array($ids) && is_int($ids[0] ?? null)) {
                    $dex = $ids[0];
                    break;
                }
            }
            $this->cache->put($key, $dex, $dex > 0 ? 30 * 86400 : 86400);
        }

        return $dex > 0 ? $dex : null;
    }

    /**
     * Every card of a set named by its id ("SV-P", "swsh8") or name: for a
     * search with a set and a number but no name. At most three sets.
     *
     * @return list<array<string, mixed>>
     */
    private function setCards(string $lang, string $words): array
    {
        $asked = CardQuery::plain($words);
        if ($asked === '') {
            return [];
        }

        $sets = $this->sets($lang);
        $ids = array_keys(array_filter($sets['sets'], fn ($s, $id) => ! in_array($id, $sets['digital'], true)
            && (CardQuery::plain($id) === $asked || (mb_strlen($asked) >= 3 && str_contains(CardQuery::plain($s['name']), $asked))), ARRAY_FILTER_USE_BOTH));

        $cards = [];
        foreach (array_slice($ids, 0, 3) as $id) {
            if (preg_match('/^[A-Za-z0-9.-]{1,40}$/', (string) $id)) {
                $cards = [...$cards, ...$this->found["$lang.set.$id"] ??= $this->cache->remember("curio.tcgdex.set.$lang.".md5((string) $id), 86400, fn () => self::brief(
                    (array) ($this->get("$lang/sets/$id")['cards'] ?? []),
                    ['sets' => [$id => $sets['sets'][$id]], 'digital' => []],
                    (string) $id
                ))];
            }
        }

        return $cards;
    }

    /**
     * Every card TCGdex lists for this filter (a name, a Pokédex number), app-only
     * TCG Pocket cards left out, in the given order. Kept a day.
     *
     * @return list<array{id: string, localId: string, name: string, image: ?string, setId: string, set: ?string, official: ?int}>
     */
    private function listed(string $lang, string $key, array $filter, callable $order): array
    {
        return $this->found["$lang.$key"] ??= $this->cache->remember("curio.tcgdex.$key.$lang", 86400, function () use ($lang, $filter, $order) {
            $cards = $this->get("$lang/cards", $filter + ['pagination:itemsPerPage' => self::MAX_CARDS]);
            $cards = self::brief($cards, $cards ? $this->sets($lang) : ['sets' => [], 'digital' => []]);
            usort($cards, fn ($a, $b) => $order($a) <=> $order($b));

            return $cards;
        });
    }

    /**
     * TCGdex's short cards in the form kept here, with their set's name and size.
     *
     * @param array{sets: array<string, array{name: string, official: ?int}>, digital: list<string>} $sets
     * @return list<array<string, mixed>>
     */
    private static function brief(array $cards, array $sets, ?string $setId = null): array
    {
        return array_values(array_filter(array_map(function ($c) use ($sets, $setId) {
            if (! is_array($c) || ! is_string($c['id'] ?? null) || ! is_string($c['name'] ?? null) || ! is_string($c['localId'] ?? null)) {
                return null;
            }
            $id = $setId ?? self::setId($c);
            $set = $sets['sets'][$id] ?? null;

            return in_array($id, $sets['digital'], true) ? null : [
                'id' => $setId !== null ? "$setId-{$c['localId']}" : $c['id'], 'localId' => $c['localId'], 'name' => $c['name'],
                'image' => is_string($c['image'] ?? null) ? $c['image'] : null, 'setId' => $id, 'set' => $set['name'] ?? null, 'official' => $set['official'] ?? null,
            ];
        }, $cards)));
    }

    /**
     * The cards from the set these words name: its code as printed on the card
     * ("FST"), TCGdex's id ("swsh8") or its name ("Fusion Strike", "fusion").
     *
     * @param list<array<string, mixed>> $cards
     * @return list<array<string, mixed>>
     */
    private function inSet(string $lang, array $cards, string $words): array
    {
        $asked = CardQuery::plain($words);
        if ($asked === '' || ! $cards) {
            return [];
        }

        $byName = array_values(array_filter($cards, fn ($c) => CardQuery::plain($c['setId']) === $asked
            || (mb_strlen($asked) >= 3 && $c['set'] !== null && str_contains(CardQuery::plain($c['set']), $asked))));

        // A set code is short and has letters ("FST"); "195264" is not one. Asian sets' ids are their codes.
        if ($byName || mb_strlen($asked) > 6 || ! preg_match('/\p{L}/u', $asked) || in_array($lang, self::ASIAN, true)) {
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

    /** The query with any printing words taken out ("Gengar 057/091 trick or trade" → "Gengar 057/091"). */
    public static function withoutPrinting(string $query): string
    {
        $plain = trim((string) preg_replace(['/\s+/u'], ' ', (string) preg_replace(self::PRINTING_WORDS, ' ', $query)));

        return $plain !== '' ? $plain : $query;
    }

    /**
     * Every way this card was printed, from TCGdex's variants_detailed: its
     * finish, then any special pattern, foil, stamps and size, as Variant
     * keys ("holo", "holo.cosmos.trick-or-trade", "normal.1st-edition").
     * Their words come from the English card (TCGdex translates "holo" as
     * "Olografica"), matched by variantId; a card only one language has
     * keeps its own words when they are plain.
     *
     * @return list<string>
     */
    private function printings(array $card, string $lang, string $id): array
    {
        $variants = array_filter((array) ($card['variants_detailed'] ?? []), 'is_array');
        if (! $variants) {
            return [];
        }

        if ($lang !== 'en' && ! in_array($lang, self::ASIAN, true)) {
            try {
                $english = [];
                foreach (array_filter((array) ($this->get("en/cards/$id")['variants_detailed'] ?? []), 'is_array') as $v) {
                    if (is_string($v['variantId'] ?? null)) {
                        $english[$v['variantId']] = $v;
                    }
                }
                $variants = array_map(fn ($v) => $english[$v['variantId'] ?? ''] ?? $v, $variants);
            } catch (LookupException $e) {
                // No English card: its own words.
            }
        }

        $keys = [];
        foreach ($variants as $v) {
            $finish = self::slug($v['type'] ?? null);
            if (! in_array($finish, self::FINISHES, true)) {
                continue;
            }
            $parts = [$finish, self::slug($v['subtype'] ?? null), self::slug($v['foil'] ?? null)];
            foreach (array_slice(array_filter((array) ($v['stamp'] ?? []), 'is_string'), 0, 3) as $stamp) {
                $parts[] = self::slug($stamp);
            }
            if (self::slug($v['size'] ?? null) === 'jumbo') {
                $parts[] = 'jumbo';
            }
            $key = implode('.', array_values(array_unique(array_filter($parts))));
            if (strlen($key) <= 100 && preg_match(Type::PRINTING, $key)) {
                $keys[] = $key;
            }
        }

        return array_slice(array_values(array_unique($keys)), 0, 30);
    }

    /** "Trick-or-Trade", "cracked ice" → "trick-or-trade", "cracked-ice"; anything else (accents, scripts) → ''. */
    private static function slug(mixed $value): string
    {
        $slug = is_string($value) ? trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($value)), '-') : '';

        return preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) && strlen($slug) <= 40 && ! preg_match('/[^\x20-\x7e]/', (string) $value) ? $slug : '';
    }

    /** "swsh3-136" with local id "136" is card 136 of set "swsh3". */
    private static function setId(array $card): string
    {
        $suffix = '-'.$card['localId'];

        return str_ends_with($card['id'], $suffix) ? substr($card['id'], 0, -strlen($suffix)) : '';
    }

    /** "136/189", as printed on the card; a Japanese-style promo "201/SV-P". */
    private static function number(string $local, mixed $official, string $setId): string
    {
        if (is_int($official) && $official > 0 && ctype_digit($local)) {
            return "$local/$official";
        }

        return str_ends_with($setId, '-P') && ctype_digit($local) ? "$local/$setId" : $local;
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
