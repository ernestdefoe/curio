<?php

namespace Ernestdefoe\Curio\Tests\integration;

use Ernestdefoe\Curio\Lookup\Budget;
use Ernestdefoe\Curio\Lookup\Ebay;
use Ernestdefoe\Curio\Lookup\Http;
use Flarum\Http\UrlGenerator;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/** eBay's Browse API against recorded answers: keys, token, search, misses and failures. */
class EbayTest extends TestCase
{
    private Repository $cache;

    private MockHandler $responses;

    /** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
    private array $sent = [];

    /** @var array<string, string> */
    private array $settings = [];

    private AbstractLogger $log;

    protected function setUp(): void
    {
        $this->cache = new Repository(new ArrayStore());
        $this->responses = new MockHandler();
        $this->settings = ['ernestdefoe-curio.ebay_app_id' => 'Forum-Curio-PRD-1a2b3c4d5-6e7f8a9b', 'ernestdefoe-curio.ebay_cert_id' => 'PRD-secretcert-0000-1111', 'default_locale' => 'fr'];
        $this->log = new class extends AbstractLogger {
            public array $lines = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->lines[] = "$level $message";
            }
        };
    }

    private function ebay(): Ebay
    {
        $stack = HandlerStack::create($this->responses);
        $stack->push(Middleware::history($this->sent));
        $url = (new \ReflectionClass(UrlGenerator::class))->newInstanceWithoutConstructor();
        $http = new class($this->cache, $url, new Client(['handler' => $stack])) extends Http {
            public function __construct(Repository $cache, UrlGenerator $url, private Client $mock)
            {
                parent::__construct($cache, $url);
            }

            protected function client(): Client
            {
                return $this->mock;
            }
        };
        $settings = new class($this->settings) implements SettingsRepositoryInterface {
            public function __construct(private array $values)
            {
            }

            public function all(): array
            {
                return $this->values;
            }

            public function get(string $key, $default = null): mixed
            {
                return $this->values[$key] ?? $default;
            }

            public function set(string $key, mixed $value): void
            {
                $this->values[$key] = $value;
            }

            public function delete(string $keyLike): void
            {
                unset($this->values[$keyLike]);
            }
        };

        return new Ebay($http, new Budget($this->cache), $this->cache, $settings, $this->log);
    }

    private static function fixture(string $name, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => str_ends_with($name, '.html') ? 'text/html' : 'application/json'], file_get_contents(__DIR__.'/../fixtures/ebay/'.$name));
    }

    /** @return list<string> */
    private function urls(): array
    {
        return array_map(fn ($t) => $t['request']->getMethod().' '.$t['request']->getUri()->getHost().$t['request']->getUri()->getPath(), $this->sent);
    }

    private function assertNothingSecretLogged(): void
    {
        foreach ($this->log->lines as $line) {
            $this->assertStringNotContainsString('secretcert', $line);
            $this->assertStringNotContainsString('FAKE-FIXTURE-TOKEN', $line);
            $this->assertStringNotContainsString(base64_encode($this->settings['ernestdefoe-curio.ebay_app_id'].':'.$this->settings['ernestdefoe-curio.ebay_cert_id']), $line);
        }
    }

    #[Test]
    public function without_keys_ebay_is_never_asked(): void
    {
        $this->settings['ernestdefoe-curio.ebay_cert_id'] = '';

        $this->assertNull($this->ebay()->product('0045496428778'));
        $this->assertSame([], $this->sent);
    }

    #[Test]
    public function a_barcode_is_named_by_what_most_listings_share(): void
    {
        $this->responses->append(self::fixture('token.json'), self::fixture('search-hit.json'), self::fixture('search-empty.json'));
        $ebay = $this->ebay();

        $found = $ebay->product('0045496428778');
        $this->assertSame(['title' => 'Metroid Dread Nintendo Switch', 'name' => 'Metroid Dread Nintendo Switch Neuf'], $found);

        [$token, $search] = array_column($this->sent, 'request');
        $this->assertSame('POST api.ebay.com/identity/v1/oauth2/token', $this->urls()[0]);
        $this->assertSame('Basic '.base64_encode('Forum-Curio-PRD-1a2b3c4d5-6e7f8a9b:PRD-secretcert-0000-1111'), $token->getHeaderLine('Authorization'));
        parse_str((string) $token->getBody(), $form);
        $this->assertSame(['grant_type' => 'client_credentials', 'scope' => 'https://api.ebay.com/oauth/api_scope'], $form);

        $this->assertSame('GET api.ebay.com/buy/browse/v1/item_summary/search', $this->urls()[1]);
        parse_str($search->getUri()->getQuery(), $query);
        $this->assertSame(['gtin' => '0045496428778', 'limit' => '10'], $query);
        $this->assertStringStartsWith('Bearer v^1.1', $search->getHeaderLine('Authorization'));
        $this->assertSame('EBAY_FR', $search->getHeaderLine('X-EBAY-C-MARKETPLACE-ID'));

        // Kept: the same barcode sends nothing; another reuses the token.
        $this->assertSame($found, $ebay->product('0045496428778'));
        $this->assertNull($ebay->product('3700664528298'));
        $this->assertSame(['POST api.ebay.com/identity/v1/oauth2/token', 'GET api.ebay.com/buy/browse/v1/item_summary/search', 'GET api.ebay.com/buy/browse/v1/item_summary/search'], $this->urls());

        // A miss is kept too.
        $this->assertNull($ebay->product('3700664528298'));
        $this->assertCount(3, $this->sent);
        $this->assertSame([], $this->log->lines);
    }

    #[Test]
    public function nothing_about_listings_or_sellers_is_kept(): void
    {
        $this->responses->append(self::fixture('token.json'), self::fixture('search-hit.json'));
        $this->ebay()->product('0045496428778');

        $kept = serialize($this->cache->get('curio.ebay.EBAY_FR.0045496428778'));
        foreach (['vendeur_', 'ebayimg', '39.90', 'itemId', '28047155690'] as $listingDetail) {
            $this->assertStringNotContainsString($listingDetail, $kept);
        }
    }

    #[Test]
    public function an_expired_token_is_renewed_once(): void
    {
        $this->responses->append(self::fixture('token.json'), self::fixture('error-401.json', 401), self::fixture('token.json'), self::fixture('search-hit.json'));

        $this->assertSame('Metroid Dread Nintendo Switch', $this->ebay()->product('0045496428778')['title']);
        $this->assertSame(['POST', 'GET', 'POST', 'GET'], array_map(fn ($t) => $t['request']->getMethod(), $this->sent));
    }

    #[Test]
    public function a_busy_ebay_is_a_miss_logged_once_and_rested(): void
    {
        $this->responses->append(self::fixture('token.json'), self::fixture('error-429.json', 429));
        $ebay = $this->ebay();

        $this->assertNull($ebay->product('0045496428778'));
        $this->assertNull($ebay->product('3700664528298'));
        // Marked down for a minute: the second barcode sent nothing.
        $this->assertCount(2, $this->sent);
        $this->assertSame(['warning [curio] eBay barcode look-up skipped: ebay request failed (429)'], $this->log->lines);
        // Not kept as a miss: asked again once eBay is back.
        $this->assertNull($this->cache->get('curio.ebay.EBAY_FR.0045496428778'));
        $this->assertNothingSecretLogged();
    }

    #[Test]
    public function a_malformed_answer_is_a_miss_that_is_not_kept(): void
    {
        $this->responses->append(self::fixture('token.json'), self::fixture('malformed.html'), self::fixture('search-hit.json'));
        $ebay = $this->ebay();

        $this->assertNull($ebay->product('0045496428778'));
        $this->assertCount(1, $this->log->lines);
        $this->assertSame('Metroid Dread Nintendo Switch', $ebay->product('0045496428778')['title']);
        $this->assertNothingSecretLogged();
    }

    #[Test]
    public function refused_keys_are_not_tried_again_for_an_hour(): void
    {
        $this->responses->append(self::fixture('token-refused.json', 401));
        $ebay = $this->ebay();

        $this->assertNull($ebay->product('0045496428778'));
        $this->assertNull($ebay->product('3700664528298'));
        $this->assertSame(['POST api.ebay.com/identity/v1/oauth2/token'], $this->urls());
        $this->assertCount(1, array_filter($this->log->lines, fn ($l) => str_contains($l, '(401)')));
        $this->assertNothingSecretLogged();
    }

    #[Test]
    public function the_ebay_site_follows_the_forums_language_unless_the_admin_picks_one(): void
    {
        foreach (['fr' => 'EBAY_FR', 'de' => 'EBAY_DE', 'en_GB' => 'EBAY_GB', 'en-GB' => 'EBAY_GB', 'en' => 'EBAY_US', 'pt_BR' => 'EBAY_US'] as $locale => $site) {
            $this->settings['default_locale'] = $locale;
            $this->assertSame($site, $this->ebay()->marketplace(), $locale);
        }

        $this->settings['ernestdefoe-curio.ebay_marketplace'] = 'EBAY_IT';
        $this->assertSame('EBAY_IT', $this->ebay()->marketplace());
        $this->settings['ernestdefoe-curio.ebay_marketplace'] = 'EBAY_MARS';
        $this->assertSame('EBAY_US', $this->ebay()->marketplace());
    }

    #[Test]
    public function listing_noise_is_left_out_of_the_name(): void
    {
        $this->assertNull(Ebay::name([]));
        $this->assertNull(Ebay::name('not a list'));
        $this->assertSame('Mario Kart 8 Deluxe Nintendo Switch', Ebay::name([['title' => 'MARIO KART 8 DELUXE NINTENDO SWITCH GAME NEW SEALED']])['title']);
        $this->assertSame('The Game of Life Switch', Ebay::name([['title' => 'The Game of Life Switch Game PAL']])['title']);
        $this->assertSame('Elden Ring PS5', Ebay::name([
            ['title' => 'Elden Ring PS5 PAL Brand New Sealed'],
            ['title' => 'Elden Ring (PS5) - FAST FREE POSTAGE'],
            ['title' => 'ELDEN RING PS5 GAME'],
            ['title' => 'Elden Ring Playstation 5 PS5 UK'],
        ])['title']);
    }
}
