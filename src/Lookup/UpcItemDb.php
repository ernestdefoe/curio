<?php

namespace Ernestdefoe\Curio\Lookup;

use Ernestdefoe\Curio\Settings;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Product names by UPC/EAN from UPCitemdb: 100 a day free with no key, more
 * with the admin's paid key. Only asked when nothing else knows the barcode,
 * and every answer is kept by the forum, so most forums stay on the free tier.
 */
class UpcItemDb
{
    /** Without a key UPCitemdb allows 100 a day; stop short so the forum is never blocked. */
    private const FREE_PER_DAY = 95;

    /** No member spends more than this much of the forum's daily look-ups. */
    private const PER_MEMBER_PER_DAY = 25;

    /** A barcode nobody knows is not asked about again for a week. */
    private const MISS_SECONDS = 7 * 86400;

    public function __construct(private Http $http, private SettingsRepositoryInterface $settings, private Cache $cache)
    {
    }

    /** The product's name, or null (unknown, or today's allowance is spent). */
    public function name(string $ean, int $actorId): ?string
    {
        if ($this->cache->has("curio.upc.miss.$ean")) {
            return null;
        }

        $key = trim((string) $this->settings->get(Settings::UPC_KEY));
        $day = date('Ymd');

        if (! $this->spend("curio.upc.member.$actorId.$day", self::PER_MEMBER_PER_DAY)
            || ($key === '' && ! $this->spend("curio.upc.forum.$day", self::FREE_PER_DAY))) {
            return null;
        }

        $name = $this->ask($ean, $key);

        if ($name === null) {
            $this->cache->put("curio.upc.miss.$ean", true, self::MISS_SECONDS);
        }

        return $name;
    }

    private function spend(string $counter, int $limit): bool
    {
        $this->cache->add($counter, 0, 2 * 86400);

        return $this->cache->increment($counter) <= $limit;
    }

    protected function ask(string $ean, string $key): ?string
    {
        $url = $key !== '' ? 'https://api.upcitemdb.com/prod/v1/lookup' : 'https://api.upcitemdb.com/prod/trial/lookup';
        $headers = $key !== '' ? ['user_key' => $key, 'key_type' => '3scale'] : [];

        $data = $this->http->json('upcitemdb', $url, ['upc' => Barcode::upc($ean)], $headers);
        $title = $data['items'][0]['title'] ?? null;

        return is_string($title) && trim($title) !== '' ? trim($title) : null;
    }
}
