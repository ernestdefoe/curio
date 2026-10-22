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
 * Yu-Gi-Oh! cards from YGOPRODeck: free, no key, names in French, German,
 * Italian or Portuguese where the reader uses one. YGOPRODeck allows 20
 * requests a second (10 here) and asks sites not to hotlink its pictures: a
 * card's picture is downloaded once, when it is added, and served by the
 * forum. Search results therefore carry no picture. A result is a card; its
 * printings (set, code, rarity) are offered as choices.
 */
class YgoProDeck implements Source, Paged
{
    private const URL = 'https://db.ygoprodeck.com/api/v7/cardinfo.php';

    private const SETS_URL = 'https://db.ygoprodeck.com/api/v7/cardsetsinfo.php';

    private const LANGUAGES = ['fr', 'de', 'it', 'pt'];

    private const MAX_PRINTINGS = 40;

    public function __construct(private Http $http, private Budget $budget, private Cache $cache, private TranslatorInterface $translator)
    {
    }

    public function key(): string
    {
        return 'ygoprodeck';
    }

    public function search(string $query): array
    {
        return $this->page($query, 1)[0];
    }

    /**
     * A name ("Dark Magician"), a page at a time; or a printing's code
     * ("LOB-EN001"), which finds that one printing. Each page is kept a day.
     */
    public function page(string $query, int $page): array
    {
        $query = trim($query);
        $lang = $this->language();
        $code = CardQuery::parse($query)->code;

        if ($query === '') {
            return [[], false];
        }

        return $this->cache->remember("curio.ygoprodeck.page.$lang.$page.".md5(mb_strtolower($code ?? $query)), 86400, function () use ($query, $lang, $page, $code) {
            if ($code !== null) {
                $cards = $this->printed($code, $lang);
                $more = false;
            } else {
                $answer = $this->get(['fname' => $query, 'num' => self::PER_PAGE, 'offset' => ($page - 1) * self::PER_PAGE], $lang, true);
                $cards = $answer['data'];
                $more = (int) ($answer['meta']['rows_remaining'] ?? 0) > 0;
            }

            return [array_map(function (array $c) use ($lang, $code) {
                $printings = self::printings($c);
                if ($code !== null) {
                    // Only the printing asked for (in each rarity it came in).
                    $printings = array_values(array_filter($printings, fn ($s) => strcasecmp($s['set_code'], $code) === 0 || strcasecmp(self::english($s['set_code']), self::english($code)) === 0)) ?: $printings;
                }
                // A code printed in two rarities is told apart by the rarity.
                $codes = array_count_values(array_column($printings, 'set_code'));

                return [
                    'id' => "$lang/{$c['id']}",
                    'title' => $c['name'],
                    'creator' => null,
                    'year' => null,
                    'cover' => null,
                    'set' => count($printings) === 1 ? $printings[0]['set_name'] : null,
                    'number' => count($printings) === 1 ? $printings[0]['set_code'] : null,
                    'variants' => array_map(fn (array $s) => [
                        'label' => $s['set_code'].($codes[$s['set_code']] > 1 && $s['set_rarity'] ? " ({$s['set_rarity']})" : ''),
                        'title' => implode(' · ', array_filter([$s['set_name'], $s['set_rarity']])),
                        'fields' => ['set' => $s['set_name'], 'number' => $s['set_code'], 'rarity' => $s['set_rarity'], 'language' => self::printLanguage($s['set_code'])],
                    ], $printings),
                ];
            }, $cards), $more];
        });
    }

    /**
     * The card printed with this code. YGOPRODeck lists English codes, so
     * "LOB-FR001" is looked up as "LOB-EN001" when it is not known itself.
     *
     * @return list<array<string, mixed>>
     */
    private function printed(string $code, string $lang): array
    {
        foreach (array_unique([$code, self::english($code)]) as $asked) {
            $this->budget->spend('ygoprodeck', 10, 5000);
            try {
                $id = $this->http->json('ygoprodeck', self::SETS_URL, ['setcode' => $asked])['id'] ?? null;
            } catch (LookupException $e) {
                if ($e->outage) {
                    throw $e;
                }
                $id = null;
            }
            if (is_int($id)) {
                return $this->get(['id' => $id], $lang);
            }
        }

        return [];
    }

    /** "LOB-FR001" → "LOB-EN001"; a code without a language stays as it is. */
    private static function english(string $code): string
    {
        return (string) preg_replace('/-[A-Z]{2}(?=\d)/i', '-EN', $code);
    }

    public function details(string $id): array
    {
        if (! preg_match('#^([a-z]{2})/(\d{1,12})$#', $id, $m) || ($m[1] !== 'en' && ! in_array($m[1], self::LANGUAGES, true))) {
            throw new LookupException("Not a YGOPRODeck id: $id");
        }

        $c = $this->get(['id' => $m[2]], $m[1])[0] ?? null;

        if (! $c) {
            throw new LookupException("YGOPRODeck has no card $id");
        }

        $image = $c['card_images'][0]['image_url'] ?? null;
        $printings = self::printings($c);
        // A card printed once: its set, code and rarity are known.
        $only = count($printings) === 1 ? $printings[0] : null;

        return [
            'title' => $c['name'],
            'creator' => null,
            'year' => null,
            'cover' => is_string($image) && str_starts_with($image, 'https://images.ygoprodeck.com/') ? $image : null,
            'wikidata' => null,
            'facts' => ['card' => [
                'set' => $only['set_name'] ?? null,
                'number' => $only['set_code'] ?? null,
                'rarity' => $only['set_rarity'] ?? null,
                // The card's name is in the reader's language; the printing's language is in its code.
                'language' => $only ? self::printLanguage($only['set_code']) : null,
            ]],
        ];
    }

    /** "LOB-EN005" is an English printing, "LOB-FR005" a French one; older codes say nothing. */
    private static function printLanguage(string $code): ?string
    {
        $languages = ['EN' => 'en', 'FR' => 'fr', 'DE' => 'de', 'IT' => 'it', 'PT' => 'pt', 'SP' => 'es', 'JP' => 'ja', 'KR' => 'ko'];

        return preg_match('/-([A-Z]{2})[A-Z]?\d/', $code, $m) ? ($languages[$m[1]] ?? null) : null;
    }

    /** @return list<array{set_name: string, set_code: string, set_rarity: ?string}> */
    private static function printings(array $card): array
    {
        $sets = array_filter((array) ($card['card_sets'] ?? []), fn ($s) => is_array($s) && is_string($s['set_name'] ?? null) && is_string($s['set_code'] ?? null));

        return array_slice(array_values(array_map(fn ($s) => [
            'set_name' => $s['set_name'], 'set_code' => $s['set_code'], 'set_rarity' => is_string($s['set_rarity'] ?? null) ? $s['set_rarity'] : null,
        ], $sets)), 0, self::MAX_PRINTINGS);
    }

    /**
     * The cards found; none (not an error) when nothing matches. With
     * $withMeta, ['data' => cards, 'meta' => YGOPRODeck's paging].
     *
     * @return array<mixed>
     */
    private function get(array $query, string $lang, bool $withMeta = false): array
    {
        $this->budget->spend('ygoprodeck', 10, 5000);

        try {
            $answer = $this->http->json('ygoprodeck', self::URL, $query + ($lang === 'en' ? [] : ['language' => $lang]));
        } catch (LookupException $e) {
            // "No card matching your query" is a 400.
            if ($e->outage) {
                throw $e;
            }
            $answer = [];
        }

        $cards = array_values(array_filter((array) ($answer['data'] ?? []), fn ($c) => is_array($c) && is_int($c['id'] ?? null) && is_string($c['name'] ?? null)));

        return $withMeta ? ['data' => $cards, 'meta' => (array) ($answer['meta'] ?? [])] : $cards;
    }

    private function language(): string
    {
        $lang = WorkTexts::base((string) $this->translator->getLocale());

        return in_array($lang, self::LANGUAGES, true) ? $lang : 'en';
    }
}
