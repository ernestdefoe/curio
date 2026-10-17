<?php

namespace Ernestdefoe\Curio\Lookup\Sources;

use Ernestdefoe\Curio\Lookup\Http;
use Ernestdefoe\Curio\Lookup\LookupException;
use Ernestdefoe\Curio\Lookup\Source;

/**
 * Albums from Deezer: no key. Deezer answers errors with HTTP 200 and an
 * `error` object; quota errors (4, 700) are treated like an outage.
 */
class Deezer implements Source
{
    private const BASE = 'https://api.deezer.com/';

    public function __construct(private Http $http)
    {
    }

    public function key(): string
    {
        return 'deezer';
    }

    public function search(string $query): array
    {
        $data = $this->get('search/album', ['q' => $query, 'limit' => 8]);

        return array_values(array_filter(array_map(fn ($a) => is_array($a) ? $this->result($a) : null, $data['data'] ?? [])));
    }

    public function details(string $id): array
    {
        if (! preg_match('/^\d{1,15}$/', $id)) {
            throw new LookupException("Not a Deezer album id: $id");
        }

        $result = $this->result($this->get("album/$id"));

        if (! $result) {
            throw new LookupException("Deezer has no album $id");
        }

        return $result + ['wikidata' => ['P2723', $id]];
    }

    /** The album with this barcode, or null. */
    public function albumForBarcode(string $code): ?string
    {
        try {
            $album = $this->get('album/upc:'.$code);
        } catch (LookupException $e) {
            if ($e->outage) {
                throw $e;
            }

            return null;
        }

        return isset($album['id']) ? (string) $album['id'] : null;
    }

    private function get(string $path, array $query = []): array
    {
        $data = $this->http->json('deezer', self::BASE.$path, $query);

        if (isset($data['error'])) {
            $code = (int) ($data['error']['code'] ?? 0);
            throw new LookupException("Deezer error $code", in_array($code, [4, 700], true));
        }

        return $data;
    }

    /** @param array<string, mixed> $album */
    private function result(array $album): ?array
    {
        if (empty($album['id']) || empty($album['title'])) {
            return null;
        }

        $year = preg_match('/^(\d{4})/', (string) ($album['release_date'] ?? ''), $m) ? (int) $m[1] : null;

        return [
            'id' => (string) $album['id'],
            'title' => (string) $album['title'],
            'creator' => isset($album['artist']['name']) ? (string) $album['artist']['name'] : null,
            'year' => $year,
            'cover' => is_string($album['cover_xl'] ?? null) ? $album['cover_xl'] : null,
        ];
    }
}
