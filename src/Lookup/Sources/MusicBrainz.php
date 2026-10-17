<?php

namespace Ernestdefoe\Curio\Lookup\Sources;

use Ernestdefoe\Curio\Lookup\Http;
use Ernestdefoe\Curio\Lookup\LookupException;
use Ernestdefoe\Curio\Lookup\Source;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Records by barcode from MusicBrainz: no key, but one request a second per
 * client, so it is only asked when Deezer does not know the barcode. A work is
 * a release group (the album across its pressings); covers come from the
 * Cover Art Archive.
 */
class MusicBrainz implements Source
{
    private const BASE = 'https://musicbrainz.org/ws/2/';

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(private Http $http, private Cache $cache)
    {
    }

    /** MusicBrainz allows one request a second per client: wait for the slot. */
    private function json(string $path, array $query): array
    {
        for ($tries = 0; $tries < 3 && ! $this->cache->add('curio.musicbrainz.slot', true, 1); $tries++) {
            usleep(1_100_000);
        }

        return $this->http->json('musicbrainz', self::BASE.$path, $query);
    }

    public function key(): string
    {
        return 'musicbrainz';
    }

    public function search(string $query): array
    {
        return [];
    }

    public function details(string $id): array
    {
        if (! preg_match(self::UUID, $id)) {
            throw new LookupException("Not a MusicBrainz release group: $id");
        }

        $group = $this->json("release-group/$id", ['inc' => 'artist-credits', 'fmt' => 'json']);

        if (empty($group['title'])) {
            throw new LookupException("MusicBrainz has no release group $id");
        }

        return [
            'title' => (string) $group['title'],
            'creator' => isset($group['artist-credit'][0]['name']) ? (string) $group['artist-credit'][0]['name'] : null,
            'year' => preg_match('/^(\d{4})/', (string) ($group['first-release-date'] ?? ''), $m) ? (int) $m[1] : null,
            'cover' => "https://coverartarchive.org/release-group/$id/front-500",
            'wikidata' => ['P436', $id],
        ];
    }

    /**
     * The release group and format of the release with this barcode.
     *
     * @return array{id: string, format: ?string}|null
     */
    public function groupForBarcode(string $code): ?array
    {
        $releases = $this->json('release/', ['query' => 'barcode:'.$code, 'fmt' => 'json', 'limit' => 1])['releases'] ?? [];
        $release = $releases[0] ?? null;
        $group = $release['release-group']['id'] ?? null;

        if (! is_string($group) || ! preg_match(self::UUID, $group)) {
            return null;
        }

        return ['id' => $group, 'format' => isset($release['media'][0]['format']) ? (string) $release['media'][0]['format'] : null];
    }
}
