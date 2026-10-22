<?php

namespace Ernestdefoe\Curio\Storage;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;

/**
 * Downloads a cover from one of the image hosts the look-up sources use, and
 * nowhere else: the URL comes from a source's answer, so it is checked against
 * an allow-list (redirects included) before anything is fetched. HTTPS only,
 * a size cap, and a short timeout.
 */
class RemoteImage
{
    public const HOSTS = [
        'covers.openlibrary.org', 'archive.org', 'cdn-images.dzcdn.net', 'e-cdns-images.dzcdn.net',
        'coverartarchive.org', 'image.tmdb.org', 'images.igdb.com',
        'assets.tcgdex.net', 'cards.scryfall.io', 'images.ygoprodeck.com',
    ];

    private const MAX_BYTES = 8 * 1024 * 1024;

    private const MAX_PIXELS = 24_000_000;

    private ?Client $http = null;

    public static function allowed(string|UriInterface $url): bool
    {
        $parts = is_string($url) ? parse_url($url) : ['scheme' => $url->getScheme(), 'host' => $url->getHost()];
        $host = strtolower($parts['host'] ?? '');

        if (($parts['scheme'] ?? '') !== 'https' || $host === '') {
            return false;
        }

        foreach (self::HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    /** The image's bytes, or null when it is not there. */
    public function fetch(string $url): ?string
    {
        if (! self::allowed($url)) {
            return null;
        }

        $this->http ??= new Client(['timeout' => 8, 'connect_timeout' => 3, 'allow_redirects' => [
            'max' => 3,
            'protocols' => ['https'],
            'on_redirect' => function (RequestInterface $request, $response, UriInterface $uri) {
                if (! self::allowed($uri)) {
                    throw new RuntimeException('Cover redirected off the allow-list');
                }
            },
        ]]);

        try {
            // Scryfall (and others) refuse a request that does not say who it is from.
            $response = $this->http->get($url, ['stream' => true, 'headers' => ['Accept' => 'image/*', 'User-Agent' => 'Curio for Flarum (+https://github.com/ernestdefoe/curio)']]);
        } catch (GuzzleException|RuntimeException) {
            return null;
        }

        $body = $response->getBody();
        $data = '';

        while (! $body->eof()) {
            $data .= $body->read(65536);

            if (strlen($data) > self::MAX_BYTES) {
                return null;
            }
        }

        // Dimensions are read from the header before anything decodes the pixels.
        $size = @getimagesizefromstring($data);

        return $size && $size[0] * $size[1] <= self::MAX_PIXELS ? $data : null;
    }
}
