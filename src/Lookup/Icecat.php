<?php

namespace Ernestdefoe\Curio\Lookup;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Product names by EAN from Open Icecat, the open part of Icecat's catalogue
 * (brands that sponsor it: Sony, Nintendo, Bandai Namco and most consoles,
 * electronics and toys sold in Europe), through its public account, so no
 * key. It knows many European boxes UPCitemdb does not. Answers are kept a
 * month and misses a week; the forum asks at most a few hundred a day.
 */
class Icecat
{
    private const URL = 'https://live.icecat.biz/api/';

    /** Icecat's public Open Icecat account. */
    private const SHOP = 'openicecat-live';

    private const PER_DAY = 300;

    private const HIT_SECONDS = 30 * 86400;

    private const MISS_SECONDS = 7 * 86400;

    public function __construct(private Http $http, private Budget $budget, private Cache $cache)
    {
    }

    /**
     * The product's own name ("Elden Ring: Shadow of the Erdtree") and its
     * full listing title (brand, edition and platform: read for details),
     * or null when Icecat does not know the barcode.
     *
     * @return array{title: string, name: string}|null
     * @throws LookupException when Icecat is down or today's allowance is spent
     */
    public function product(string $ean): ?array
    {
        $key = "curio.icecat.$ean";
        $known = $this->cache->get($key);

        if ($known !== null) {
            return $known ?: null;
        }

        $this->budget->spend('icecat', 2, self::PER_DAY);

        $found = $this->ask($ean);
        $this->cache->put($key, $found ?? false, $found ? self::HIT_SECONDS : self::MISS_SECONDS);

        return $found;
    }

    /** @return array{title: string, name: string}|null */
    protected function ask(string $ean): ?array
    {
        // Unknown and closed (Full Icecat only) products both come back as a 4xx without data.
        try {
            $data = $this->http->json('icecat', self::URL, ['shopname' => self::SHOP, 'lang' => 'en', 'GTIN' => $ean, 'content' => 'GeneralInfo']);
        } catch (LookupException $e) {
            if ($e->outage) {
                throw $e;
            }

            return null;
        }

        $info = $data['data']['GeneralInfo'] ?? [];
        $title = trim((string) ($info['ProductName'] ?? ''));
        $name = trim((string) ($info['Title'] ?? ''));

        return $title !== '' ? ['title' => $title, 'name' => $name !== '' ? $name : $title] : null;
    }
}
