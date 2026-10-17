<?php

namespace Ernestdefoe\Curio\Lookup\Sources;

use Ernestdefoe\Curio\Lookup\Http;
use Ernestdefoe\Curio\Lookup\LookupException;
use Ernestdefoe\Curio\Lookup\Source;

/** Books from Open Library: no key. A book is a work (all its editions). */
class OpenLibrary implements Source
{
    private const BASE = 'https://openlibrary.org/';

    private const FIELDS = 'key,title,author_name,first_publish_year,cover_i';

    public function __construct(private Http $http)
    {
    }

    public function key(): string
    {
        return 'openlibrary';
    }

    public function search(string $query): array
    {
        $docs = $this->http->json('openlibrary', self::BASE.'search.json', ['q' => $query, 'fields' => self::FIELDS, 'limit' => 8], [], 12)['docs'] ?? [];

        return array_values(array_filter(array_map(fn ($doc) => is_array($doc) ? $this->result($doc) : null, $docs)));
    }

    public function details(string $id): array
    {
        if (! preg_match('/^OL\d{1,12}W$/', $id)) {
            throw new LookupException("Not an Open Library work id: $id");
        }

        $doc = $this->http->json('openlibrary', self::BASE.'search.json', ['q' => "key:/works/$id", 'fields' => self::FIELDS, 'limit' => 1], [], 12)['docs'][0] ?? null;
        $result = is_array($doc) ? $this->result($doc) : null;

        if (! $result) {
            throw new LookupException("Open Library has no work $id");
        }

        return $result + ['wikidata' => ['P648', $id]];
    }

    /** The work an ISBN belongs to, or null. */
    public function workForIsbn(string $isbn): ?string
    {
        $edition = $this->http->json('openlibrary', self::BASE."isbn/$isbn.json", [], [], 12);
        $key = $edition['works'][0]['key'] ?? null;

        return is_string($key) && preg_match('#^/works/(OL\d{1,12}W)$#', $key, $m) ? $m[1] : null;
    }

    /** @param array<string, mixed> $doc */
    private function result(array $doc): ?array
    {
        if (! preg_match('#^/works/(OL\d{1,12}W)$#', (string) ($doc['key'] ?? ''), $m) || empty($doc['title'])) {
            return null;
        }

        $cover = is_int($doc['cover_i'] ?? null) && $doc['cover_i'] > 0 ? "https://covers.openlibrary.org/b/id/{$doc['cover_i']}-L.jpg" : null;

        return [
            'id' => $m[1],
            'title' => (string) $doc['title'],
            'creator' => implode(', ', array_slice((array) ($doc['author_name'] ?? []), 0, 2)) ?: null,
            'year' => isset($doc['first_publish_year']) ? (int) $doc['first_publish_year'] : null,
            'cover' => $cover,
        ];
    }
}
