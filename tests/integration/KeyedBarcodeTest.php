<?php

namespace Ernestdefoe\Curio\Tests\integration;

use Ernestdefoe\Curio\Lookup\BarcodeLookupCom;
use Ernestdefoe\Curio\Lookup\Budget;
use Ernestdefoe\Curio\Lookup\EanSearch;
use Ernestdefoe\Curio\Lookup\Http;
use Ernestdefoe\Curio\Lookup\KeyedBarcodeSource;
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

/** barcodelookup.com and EAN-Search.org against answers shaped like their documented (and recorded) ones. */
class KeyedBarcodeTest extends TestCase
{
    private const BL_KEY = 'bl-secret-key-0123456789';

    private const ES_TOKEN = 'es-secret-token-abcdef';

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
        $this->settings = ['ernestdefoe-curio.barcodelookup_key' => self::BL_KEY, 'ernestdefoe-curio.eansearch_token' => self::ES_TOKEN, 'default_locale' => 'fr'];
        $this->log = new class extends AbstractLogger {
            public array $lines = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->lines[] = "$level $message";
            }
        };
    }

    /** @param class-string<KeyedBarcodeSource> $class */
    private function source(string $class): KeyedBarcodeSource
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

        return new $class($http, new Budget($this->cache), $this->cache, $settings, $this->log);
    }

    private static function fixture(string $name, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => str_ends_with($name, '.html') ? 'text/html' : 'application/json'], file_get_contents(__DIR__.'/../fixtures/'.$name));
    }

    /** @return array<string, string> */
    private function query(int $i): array
    {
        parse_str($this->sent[$i]['request']->getUri()->getQuery(), $query);

        return $query;
    }

    private function assertNothingSecretLogged(): void
    {
        foreach ($this->log->lines as $line) {
            $this->assertStringNotContainsString(self::BL_KEY, $line);
            $this->assertStringNotContainsString(self::ES_TOKEN, $line);
            $this->assertStringNotContainsString('api.', $line);
        }
    }

    #[Test]
    public function without_a_key_neither_is_ever_asked(): void
    {
        $this->settings = ['ernestdefoe-curio.barcodelookup_key' => '  ', 'default_locale' => 'fr'];

        $this->assertNull($this->source(BarcodeLookupCom::class)->product('0045496428778', 3));
        $this->assertNull($this->source(EanSearch::class)->product('0045496428778', 3));
        $this->assertSame([], $this->sent);
    }

    #[Test]
    public function barcodelookup_gives_the_title_and_nothing_else_is_kept(): void
    {
        $this->responses->append(self::fixture('barcodelookup/product-hit.json'), new Response(404, [], ''));
        $source = $this->source(BarcodeLookupCom::class);

        $found = $source->product('0045496428778', 3);
        $this->assertSame(['title' => 'Metroid Dread - Nintendo Switch', 'name' => 'Metroid Dread - Nintendo Switch Nintendo Switch'], $found);
        $this->assertSame('api.barcodelookup.com/v3/products', $this->sent[0]['request']->getUri()->getHost().$this->sent[0]['request']->getUri()->getPath());
        $this->assertSame(['barcode' => '0045496428778', 'key' => self::BL_KEY], $this->query(0));

        // Kept a day: asked once. Images, stores, prices and reviews are not kept.
        $this->assertSame($found, $source->product('0045496428778', 3));
        $kept = serialize($this->cache->get('curio.barcodelookup.'.md5(self::BL_KEY).'.0045496428778'));
        foreach (['images.barcodelookup', 'Newegg', '41.38', 'Josh Keller', 'intergalactic'] as $theirs) {
            $this->assertStringNotContainsString($theirs, $kept);
        }

        // 404 "No data returned" is a miss, kept a week.
        $this->assertNull($source->product('3700664528298', 3));
        $this->assertNull($source->product('3700664528298', 3));
        $this->assertCount(2, $this->sent);
        $this->assertSame([], $this->log->lines);
    }

    #[Test]
    public function a_refused_barcodelookup_key_rests_an_hour_and_is_logged_once_without_it(): void
    {
        $this->responses->append(self::fixture('barcodelookup/error-403.html', 403));
        $source = $this->source(BarcodeLookupCom::class);

        $this->assertNull($source->product('0045496428778', 3));
        $this->assertNull($source->product('3700664528298', 3));
        $this->assertCount(1, $this->sent);
        $this->assertSame(['warning [curio] barcodelookup.com barcode look-up skipped: barcodelookup request failed (403)'], $this->log->lines);
        // Not kept as a miss.
        $this->assertNull($this->cache->get('curio.barcodelookup.'.md5(self::BL_KEY).'.0045496428778'));
        $this->assertNothingSecretLogged();

        // A new key is tried at once.
        $this->settings['ernestdefoe-curio.barcodelookup_key'] = 'another-key';
        $this->responses->append(self::fixture('barcodelookup/empty.json'));
        $this->assertNull($this->source(BarcodeLookupCom::class)->product('0045496428778', 3));
        $this->assertCount(2, $this->sent);
    }

    #[Test]
    public function a_busy_service_is_a_miss_that_is_not_kept(): void
    {
        $this->responses->append(new Response(429, [], ''), self::fixture('barcodelookup/product-hit.json'));
        $source = $this->source(BarcodeLookupCom::class);

        $this->assertNull($source->product('0045496428778', 3));
        // Rested a minute, then asked again.
        $this->assertNull($source->product('0045496428778', 3));
        $this->assertCount(1, $this->sent);
        $this->cache->forget('curio.down.barcodelookup');
        $this->assertSame('Metroid Dread - Nintendo Switch', $source->product('0045496428778', 3)['title']);
        $this->assertCount(1, $this->log->lines);
        $this->assertNothingSecretLogged();
    }

    #[Test]
    public function eansearch_asks_in_the_forums_language_and_keeps_names_a_month(): void
    {
        $this->responses->append(self::fixture('eansearch/hit.json'), self::fixture('eansearch/not-found.json'));
        $source = $this->source(EanSearch::class);

        $this->assertSame(['title' => 'Michael Jackson, Thriller', 'name' => 'Michael Jackson, Thriller'], $source->product('5099750442227', 3));
        $this->assertSame('api.ean-search.org/api', $this->sent[0]['request']->getUri()->getHost().$this->sent[0]['request']->getUri()->getPath());
        $this->assertSame(['token' => self::ES_TOKEN, 'op' => 'barcode-lookup', 'format' => 'json', 'ean' => '5099750442227', 'language' => '6'], $this->query(0));

        // "Barcode not found" is a miss, kept; neither is asked twice.
        $this->assertNull($source->product('3700664528298', 3));
        $source->product('5099750442227', 3);
        $source->product('3700664528298', 3);
        $this->assertCount(2, $this->sent);
        $this->assertSame([], $this->log->lines);

        $this->settings['default_locale'] = 'ja';
        $this->responses->append(self::fixture('eansearch/hit.json'));
        $this->source(EanSearch::class)->product('5099750442228', 3);
        $this->assertSame('99', $this->query(2)['language']);
    }

    #[Test]
    public function an_invalid_eansearch_token_rests_an_hour(): void
    {
        // Recorded: api.ean-search.org answers a bad token with 401 and this body.
        $this->responses->append(self::fixture('eansearch/invalid-token.json', 401));
        $source = $this->source(EanSearch::class);

        $this->assertNull($source->product('5099750442227', 3));
        $this->assertNull($source->product('3700664528298', 3));
        $this->assertCount(1, $this->sent);
        $this->assertSame(['warning [curio] EAN-Search barcode look-up skipped: eansearch request failed (401)'], $this->log->lines);
        $this->assertNothingSecretLogged();
    }

    #[Test]
    public function an_eansearch_error_in_the_body_is_a_miss_that_is_not_kept(): void
    {
        $this->responses->append(new Response(200, [], '[{"error":"Invalid token"}]'), new Response(200, [], '<html>maintenance</html>'));
        $source = $this->source(EanSearch::class);

        $this->assertNull($source->product('5099750442227', 3));
        // Token errors rest the token even when answered with a 200.
        $this->assertNull($source->product('5099750442227', 3));
        $this->assertCount(1, $this->sent);
        $this->assertSame(['warning [curio] EAN-Search barcode look-up skipped: eansearch said: Invalid token'], $this->log->lines);

        $this->settings['ernestdefoe-curio.eansearch_token'] = 'fresh-token';
        $this->assertNull($this->source(EanSearch::class)->product('5099750442227', 3));
        $this->assertNull($this->cache->get('curio.eansearch.'.md5('fresh-token').'.5099750442227'));
        $this->assertCount(2, $this->log->lines);
    }

    #[Test]
    public function members_and_the_forum_have_a_daily_allowance(): void
    {
        for ($i = 0; $i < 26; $i++) {
            $this->responses->append(self::fixture('eansearch/not-found.json'));
        }
        $source = $this->source(EanSearch::class);

        for ($i = 0; $i < 26; $i++) {
            $source->product(str_pad((string) $i, 13, '0', STR_PAD_LEFT), 7);
        }
        // The 26th is not sent: a member spends at most 25 a day.
        $this->assertCount(25, $this->sent);
        $source->product('9999999999999', 8);
        $this->assertCount(26, $this->sent);
    }
}
