<?php

namespace Ernestdefoe\Curio\Translation;

use Ernestdefoe\Curio\Lookup\Http;

/**
 * Wikidata and Wikipedia, no key: the item that holds an id another database
 * gave us (P5794 = IGDB slug, P648 = Open Library work…), its labels and short
 * descriptions in every language, its Wikipedia articles, and an article's lead.
 */
class Wikidata
{
    public function __construct(private Http $http)
    {
    }

    /** The item (Q123) holding $property = $value, or null. */
    public function item(string $property, string $value): ?string
    {
        if (! preg_match('/^P\d{1,9}$/', $property) || $value === '' || preg_match('/["\s]/', $value)) {
            return null;
        }

        $found = $this->http->json('wikidata', 'https://www.wikidata.org/w/api.php', [
            'action' => 'query', 'list' => 'search', 'srsearch' => "haswbstatement:$property=$value",
            'srlimit' => 1, 'srprop' => '', 'format' => 'json', 'formatversion' => 2,
        ]);
        $item = $found['query']['search'][0]['title'] ?? null;

        return is_string($item) && preg_match('/^Q\d{1,12}$/', $item) ? $item : null;
    }

    /**
     * The item for a title when no id links it: a search within one class
     * (album, literary work…), accepted only when a label matches the title
     * exactly and either the year of publication agrees or the item's
     * description names the creator (a 2015 remaster of a 1959 album).
     */
    public function find(string $title, ?string $creator, ?int $year, string $class): ?string
    {
        $wanted = self::normalise($title);

        if ($wanted === '' || ! preg_match('/^Q\d{1,12}$/', $class)) {
            return null;
        }

        $found = $this->http->json('wikidata', 'https://www.wikidata.org/w/api.php', [
            'action' => 'query', 'list' => 'search',
            'srsearch' => trim('"'.str_replace('"', '', $title).'" '.str_replace('"', '', (string) $creator)).' haswbstatement:P31='.$class,
            'srlimit' => 3, 'srprop' => '', 'format' => 'json', 'formatversion' => 2,
        ]);
        $ids = array_values(array_filter(array_column($found['query']['search'] ?? [], 'title'), fn ($id) => is_string($id) && preg_match('/^Q\d{1,12}$/', $id)));

        if (! $ids) {
            return null;
        }

        $data = $this->http->json('wikidata', 'https://www.wikidata.org/w/api.php', [
            'action' => 'wbgetentities', 'ids' => implode('|', $ids), 'props' => 'labels|descriptions|claims', 'languages' => 'en|mul',
            'format' => 'json', 'formatversion' => 2,
        ]);

        foreach ($ids as $id) {
            $entity = $data['entities'][$id] ?? [];
            $labels = array_map(fn ($l) => self::normalise((string) ($l['value'] ?? '')), (array) ($entity['labels'] ?? []));

            if (! in_array($wanted, $labels, true)) {
                continue;
            }

            $published = $entity['claims']['P577'][0]['mainsnak']['datavalue']['value']['time'] ?? null;
            $itemYear = is_string($published) && preg_match('/^\+?(\d{4})-/', $published, $m) ? (int) $m[1] : null;

            $described = (string) ($entity['descriptions']['en']['value'] ?? '');
            $byCreator = $creator && $described !== '' && str_contains(self::normalise($described), self::normalise($creator));
            $sameYear = ! $year || ! $itemYear || abs($year - $itemYear) <= 1;

            if ($sameYear || $byCreator) {
                return $id;
            }
        }

        return null;
    }

    private static function normalise(string $title): string
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($title)) ?? '') ?? '');
    }

    /**
     * Labels and descriptions in $languages, and every Wikipedia it has an article on.
     *
     * @param list<string> $languages
     * @return array{labels: array<string, string>, descriptions: array<string, string>, wikis: array<string, string>}
     */
    public function entity(string $item, array $languages): array
    {
        $empty = ['labels' => [], 'descriptions' => [], 'wikis' => []];

        if (! preg_match('/^Q\d{1,12}$/', $item)) {
            return $empty;
        }

        $data = $this->http->json('wikidata', 'https://www.wikidata.org/w/api.php', [
            'action' => 'wbgetentities', 'ids' => $item, 'props' => 'labels|descriptions|sitelinks',
            'languages' => implode('|', array_unique([...$languages, 'en', 'mul'])), 'format' => 'json', 'formatversion' => 2,
        ]);
        $entity = $data['entities'][$item] ?? null;

        if (! is_array($entity)) {
            return $empty;
        }

        $values = fn (string $key) => array_filter(array_map(fn ($v) => is_array($v) ? (string) ($v['value'] ?? '') : '', (array) ($entity[$key] ?? [])));
        $wikis = [];

        foreach ((array) ($entity['sitelinks'] ?? []) as $site => $link) {
            // enwiki, frwiki… but not commonswiki, specieswiki or the wikiquote/wikisource sites.
            if (preg_match('/^([a-z]{2,3}(?:_[a-z]+)?)wiki$/', (string) $site, $m) && ! in_array($m[1], ['commons', 'species', 'meta'], true)) {
                $wikis[str_replace('_', '-', $m[1])] = (string) ($link['title'] ?? '');
            }
        }

        return ['labels' => $values('labels'), 'descriptions' => $values('descriptions'), 'wikis' => $wikis];
    }

    /**
     * An article's lead, or null for a missing article, a disambiguation page or a stub.
     *
     * @return array{extract: string, url: string}|null
     */
    public function summary(string $wiki, string $title): ?array
    {
        if (! preg_match('/^[a-z]{2,3}(-[a-z]+)?$/', $wiki) || $title === '') {
            return null;
        }

        $page = $this->http->json('wikipedia', "https://$wiki.wikipedia.org/api/rest_v1/page/summary/".rawurlencode(str_replace(' ', '_', $title)));
        $extract = trim((string) ($page['extract'] ?? ''));
        $url = (string) ($page['content_urls']['desktop']['page'] ?? '');

        if (($page['type'] ?? '') !== 'standard' || $extract === '' || ! str_starts_with($url, "https://$wiki.wikipedia.org/wiki/")) {
            return null;
        }

        return ['extract' => $extract, 'url' => $url];
    }
}
