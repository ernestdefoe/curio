<?php

namespace Ernestdefoe\Curio\Lookup;

use Ernestdefoe\Curio\Settings;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Log\LoggerInterface;

/**
 * Product names by barcode from eBay's Browse API, for forums that create
 * their own (free) eBay developer app. Only asked when the free services
 * miss, never without keys, and never more than 1,000 times a day (eBay
 * allows 5,000). Listing titles are noisy, so the name is the words most of
 * the listings for the barcode share. Nothing about listings, sellers or
 * buyers is kept, and eBay's photos are not used: the name lasts a day
 * (eBay asks that its content be no older than that), a miss a week.
 * Any eBay failure is a miss, logged once a day, and never stops the others.
 */
class Ebay
{
    private const TOKEN_URL = 'https://api.ebay.com/identity/v1/oauth2/token';

    private const SEARCH_URL = 'https://api.ebay.com/buy/browse/v1/item_summary/search';

    private const SCOPE = 'https://api.ebay.com/oauth/api_scope';

    private const PER_SECOND = 2;

    private const PER_DAY = 1000;

    private const HIT_SECONDS = 86400;

    private const MISS_SECONDS = 7 * 86400;

    /** Forum language → eBay site, when the admin has not picked one. */
    private const MARKETPLACES = [
        'fr' => 'EBAY_FR', 'de' => 'EBAY_DE', 'en_gb' => 'EBAY_GB', 'it' => 'EBAY_IT', 'es' => 'EBAY_ES', 'nl' => 'EBAY_NL', 'pl' => 'EBAY_PL',
    ];

    public const CHOICES = ['EBAY_US', 'EBAY_GB', 'EBAY_DE', 'EBAY_FR', 'EBAY_IT', 'EBAY_ES', 'EBAY_NL', 'EBAY_BE', 'EBAY_AT', 'EBAY_CH', 'EBAY_IE', 'EBAY_PL', 'EBAY_CA', 'EBAY_AU'];

    /** Words sellers add to a listing that never belong to a product's name. */
    private const LISTING_WORDS = ['new', 'sealed', 'brand', 'factory', 'neuf', 'scelle', 'scellé', 'blister', 'neu', 'ovp', 'nuovo', 'nuevo', 'sigillato',
        'precintado', 'used', 'occasion', 'gebraucht', 'tested', 'mint', 'rare', 'boxed', 'cib', 'p&p', 'tbe'];

    /** Words that end a listing ("… Switch Game PAL") but can start a title ("Game of Life"). */
    private const TAIL_WORDS = ['game', 'games', 'videogame', 'jeu', 'jeux', 'spiel', 'gioco', 'juego', 'videojuego', 'pal', 'eu', 'uk', 'fr', 'de', 'version',
        'edition', 'complete', 'with', 'manual', 'notice', 'boite', 'boîte', 'box', 'disc', 'only'];

    public function __construct(
        private Http $http,
        private Budget $budget,
        private Cache $cache,
        private SettingsRepositoryInterface $settings,
        private LoggerInterface $log
    ) {
    }

    public function configured(): bool
    {
        return $this->appId() !== '' && $this->certId() !== '';
    }

    /**
     * The name most listings agree on, and the most telling listing title
     * (read for platform and format), or null when eBay has nothing, is not
     * set up, or fails.
     *
     * @return array{title: string, name: string}|null
     */
    public function product(string $ean): ?array
    {
        if (! $this->configured()) {
            return null;
        }

        $market = $this->marketplace();
        $key = "curio.ebay.$market.$ean";
        $known = $this->cache->get($key);

        if ($known !== null) {
            return $known ?: null;
        }

        try {
            $this->budget->spend('ebay', self::PER_SECOND, self::PER_DAY);
            $data = $this->search($ean, $market);
            if (! isset($data['total']) && ! isset($data['itemSummaries'])) {
                throw new LookupException('ebay answered with something other than search results');
            }
            $found = self::name($data['itemSummaries'] ?? []);
        } catch (LookupException $e) {
            $this->failed($e);

            return null;
        }

        $this->cache->put($key, $found ?? false, $found ? self::HIT_SECONDS : self::MISS_SECONDS);

        return $found;
    }

    /** The eBay site asked: the admin's choice, else the forum's language, else the US. */
    public function marketplace(): string
    {
        $chosen = strtoupper(trim((string) $this->settings->get(Settings::EBAY_MARKETPLACE)));

        if (in_array($chosen, self::CHOICES, true)) {
            return $chosen;
        }

        $locale = strtolower(str_replace('-', '_', (string) $this->settings->get('default_locale')));

        return self::MARKETPLACES[$locale] ?? self::MARKETPLACES[explode('_', $locale)[0]] ?? 'EBAY_US';
    }

    /**
     * The listings' shared name: the listings for the most common catalogue
     * product (epid) when eBay gives one, then the words at least half of
     * them use, in the order of the listing that has most of those words.
     *
     * @param mixed $summaries
     * @return array{title: string, name: string}|null
     */
    public static function name(mixed $summaries): ?array
    {
        $titles = [];
        $epids = [];

        foreach (is_array($summaries) ? $summaries : [] as $summary) {
            $title = is_array($summary) && is_string($summary['title'] ?? null) ? trim($summary['title']) : '';
            if ($title !== '') {
                $epid = is_scalar($summary['epid'] ?? null) ? (string) $summary['epid'] : '';
                $titles[] = [$title, $epid];
                $epids[$epid] = ($epids[$epid] ?? 0) + ($epid === '' ? 0 : 1);
            }
        }

        arsort($epids);
        $top = (string) array_key_first($epids);
        if ($top !== '' && $epids[$top] >= 2) {
            $titles = array_values(array_filter($titles, fn ($t) => $t[1] === $top));
        }

        if (! $titles) {
            return null;
        }

        $words = array_map(fn ($t) => self::words($t[0]), $titles);
        $counts = [];
        foreach ($words as $list) {
            foreach (array_unique(array_column($list, 1)) as $norm) {
                $counts[$norm] = ($counts[$norm] ?? 0) + 1;
            }
        }

        $needed = max(1, (int) ceil(count($titles) / 2));
        $shared = fn (array $list) => array_values(array_filter($list, fn ($w) => $w[1] !== '' && $counts[$w[1]] >= $needed));
        // Most shared words, then not written in capitals, then shortest.
        $rank = fn (int $i) => [count($shared($words[$i])), mb_strtoupper($titles[$i][0]) !== $titles[$i][0], -mb_strlen($titles[$i][0])];
        $best = 0;
        foreach (array_keys($words) as $i) {
            if ($rank($i) > $rank($best)) {
                $best = $i;
            }
        }

        $name = implode(' ', array_column($shared($words[$best]), 0));
        $kept = array_values(array_filter($shared($words[$best]), fn ($w) => ! in_array($w[1], self::LISTING_WORDS, true)));
        while (count($kept) > 1 && in_array($kept[count($kept) - 1][1], array_merge(self::TAIL_WORDS, ['']), true)) {
            array_pop($kept);
        }
        $title = implode(' ', array_column($kept, 0));
        $title = trim(preg_replace('/^[\s\-–|,:]+|[\s\-–|,:]+$/u', '', $title) ?? '');
        if (mb_strtoupper($title) === $title) {
            $title = mb_convert_case(mb_strtolower($title), MB_CASE_TITLE);
        }

        return $title !== '' ? ['title' => mb_substr($title, 0, 200), 'name' => mb_substr($name, 0, 255)] : null;
    }

    /** @return list<array{0: string, 1: string}> each word as written and as compared */
    private static function words(string $title): array
    {
        $title = preg_replace('/[\[(][^\])]*[\])]/u', ' ', $title) ?? $title;
        $words = [];

        foreach (preg_split('/\s+/u', trim($title)) ?: [] as $word) {
            $norm = mb_strtolower(preg_replace('/[^\p{L}\p{N}&]+/u', '', $word) ?? '');
            $words[] = [$word, $norm];
        }

        return $words;
    }

    /** @return array<mixed> */
    protected function search(string $ean, string $market): array
    {
        $ask = fn (string $token) => $this->http->json('ebay', self::SEARCH_URL, ['gtin' => $ean, 'limit' => 10], [
            'Authorization' => 'Bearer '.$token,
            'X-EBAY-C-MARKETPLACE-ID' => $market,
        ]);
        $token = $this->token();

        try {
            return $ask($token);
        } catch (LookupException $e) {
            if (! $e->unauthorized) {
                throw $e;
            }
            // An expired or revoked token: one fresh one, one more try.
            $this->cache->forget($this->tokenKey());

            return $ask($this->token());
        }
    }

    private function token(): string
    {
        if ($token = $this->cache->get($this->tokenKey())) {
            return (string) $token;
        }

        // Keys eBay refused are not tried again for an hour.
        $refused = $this->tokenKey().'.refused';
        if ($this->cache->has($refused)) {
            throw new LookupException('ebay keys were refused', true);
        }

        try {
            $data = $this->http->post('ebay-token', self::TOKEN_URL, [], [
                'Authorization' => 'Basic '.base64_encode($this->appId().':'.$this->certId()),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ], http_build_query(['grant_type' => 'client_credentials', 'scope' => self::SCOPE]));
        } catch (LookupException $e) {
            if (! $e->outage) {
                $this->cache->put($refused, true, 3600);
            }

            throw $e;
        }

        if (! is_string($data['access_token'] ?? null) || $data['access_token'] === '') {
            throw new LookupException('ebay issued no token');
        }

        // About two hours; kept until five minutes before it ends.
        $ttl = (int) ($data['expires_in'] ?? 7200);
        $this->cache->put($this->tokenKey(), $data['access_token'], max(60, $ttl - 300));

        return $data['access_token'];
    }

    /** Per keyset, so new keys start a new token. */
    private function tokenKey(): string
    {
        return 'curio.ebay.token.'.md5($this->appId().':'.$this->certId());
    }

    /** One line a day per kind of failure; the message never holds the keys or a token. */
    private function failed(LookupException $e): void
    {
        // Resting after a failure is that failure's echo, already logged.
        if (str_ends_with($e->getMessage(), 'is marked down')) {
            return;
        }

        $kind = preg_replace('/[^a-z0-9]+/', '-', strtolower($e->getMessage()));

        if ($this->cache->add("curio.ebay.logged.$kind.".date('Ymd'), true, 2 * 86400)) {
            $this->log->warning('[curio] eBay barcode look-up skipped: '.$e->getMessage());
        }
    }

    private function appId(): string
    {
        return trim((string) $this->settings->get(Settings::EBAY_APP_ID));
    }

    private function certId(): string
    {
        return trim((string) $this->settings->get(Settings::EBAY_CERT_ID));
    }
}
