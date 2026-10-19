<?php

namespace Ernestdefoe\Curio\Lookup;

use Flarum\Http\UrlGenerator;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * JSON over HTTPS for every look-up service. Each request names Curio and the
 * forum in the User-Agent (Wikimedia, MusicBrainz and Open Library ask for it).
 * A service that fails with a connection error, a 5xx or a 429 is left alone
 * for a minute, so an outage costs one timeout, not one per member.
 */
class Http
{
    private const DOWN_SECONDS = 60;

    private ?Client $client = null;

    public function __construct(private Cache $cache, private UrlGenerator $url)
    {
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     * @return array<mixed>
     * @throws LookupException
     */
    public function json(string $service, string $url, array $query = [], array $headers = [], float $timeout = 6): array
    {
        return $this->send('GET', $service, $url, ['query' => $query, 'headers' => $headers, 'timeout' => $timeout]);
    }

    /**
     * A POST (IGDB queries, Twitch tokens). A 401 is thrown as `unauthorized`
     * so a caller holding a token can renew it once.
     *
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     * @return array<mixed>
     * @throws LookupException
     */
    public function post(string $service, string $url, array $query = [], array $headers = [], ?string $body = null, float $timeout = 6): array
    {
        return $this->send('POST', $service, $url, ['query' => $query, 'headers' => $headers, 'body' => $body, 'timeout' => $timeout]);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<mixed>
     * @throws LookupException
     */
    private function send(string $method, string $service, string $url, array $options): array
    {
        $down = 'curio.down.'.$service;

        if ($this->cache->has($down)) {
            throw new LookupException("$service is marked down", true);
        }

        try {
            $response = $this->client()->request($method, $url, array_filter($options, fn ($v) => $v !== null));
        } catch (GuzzleException $e) {
            $status = $e instanceof RequestException ? $e->getResponse()?->getStatusCode() : null;

            if ($status === 404) {
                return [];
            }

            $outage = $e instanceof ConnectException || $status === null || $status === 429 || $status >= 500;

            if ($outage) {
                $this->cache->put($down, true, self::DOWN_SECONDS);
            }

            throw new LookupException("$service request failed ($status)", $outage, $status === 401);
        }

        $body = json_decode((string) $response->getBody(), true);

        return is_array($body) ? $body : [];
    }

    private function client(): Client
    {
        return $this->client ??= new Client(['connect_timeout' => 3, 'headers' => [
            'User-Agent' => 'Curio for Flarum (+https://github.com/ernestdefoe/curio; '.$this->url->to('forum')->base().')',
            'Accept' => 'application/json',
        ]]);
    }
}
