<?php

namespace Ernestdefoe\Curio\Lookup\Sources;

use Ernestdefoe\Curio\Lookup\Budget;
use Ernestdefoe\Curio\Lookup\Http;
use Ernestdefoe\Curio\Lookup\LookupException;
use Ernestdefoe\Curio\Lookup\Source;
use Ernestdefoe\Curio\Translation\WorkTexts;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Pokémon cards from TCGdex (tcgdex.dev): free, no key, in the reader's
 * language where TCGdex has it (names, sets and rarities in French, German…).
 * Each result is one printing: its set and number tell them apart.
 */
class TcgDex implements Source
{
    private const BASE = 'https://api.tcgdex.net/v2/';

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
        $query = trim($query);
        $lang = $this->language();

        if ($query === '') {
            return [];
        }

        return $this->cache->remember("curio.tcgdex.search.$lang.".md5(mb_strtolower($query)), 86400, function () use ($query, $lang) {
            $cards = array_values(array_filter(
                $this->get("$lang/cards", ['name' => $query, 'pagination:itemsPerPage' => 50]),
                fn ($c) => is_array($c) && is_string($c['id'] ?? null) && is_string($c['name'] ?? null) && is_string($c['localId'] ?? null)
            ));
            $sets = $cards ? $this->sets($lang) : ['sets' => [], 'digital' => []];
            // Pokémon TCG Pocket's cards exist only in the app: nothing to collect.
            $cards = array_values(array_filter($cards, fn ($c) => ! in_array(self::setId($c), $sets['digital'], true)));

            // The name itself before longer ones ("Pikachu" before "Pikachu ex"), pictured ones first.
            $exact = mb_strtolower($query);
            usort($cards, fn ($a, $b) => [mb_strtolower($a['name']) !== $exact, ! isset($a['image'])] <=> [mb_strtolower($b['name']) !== $exact, ! isset($b['image'])]);

            return array_map(function (array $c) use ($lang, $sets) {
                $set = $sets['sets'][self::setId($c)] ?? null;

                return [
                    'id' => "$lang/{$c['id']}",
                    'title' => $c['name'],
                    'creator' => $set['name'] ?? null,
                    'year' => null,
                    'cover' => self::image($c['image'] ?? null, 'low'),
                    'number' => self::number($c['localId'], $set['official'] ?? null),
                ];
            }, array_slice($cards, 0, 8));
        });
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
            'creator' => $set,
            'year' => null,
            'cover' => self::image($c['image'] ?? null, 'high'),
            'wikidata' => null,
            'facts' => ['card' => [
                'set' => $set,
                'number' => self::number($c['localId'], $c['set']['cardCount']['official'] ?? null),
                'rarity' => $rarity,
                'language' => $m[1],
            ]],
        ];
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
