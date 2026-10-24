<?php

namespace Ernestdefoe\Curio\Lookup;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Log\LoggerInterface;

/**
 * A paid barcode service the admin can add with their own key. Never asked
 * without one, at most so many times a second, a day and per member a day,
 * and never twice for the same barcode while its answer is kept. A key the
 * service refuses rests an hour. Any failure is a miss, logged once a day:
 * it never stops the other services or the member's search.
 */
abstract class KeyedBarcodeSource
{
    use LogsOnce;

    protected const PER_SECOND = 2;

    protected const PER_DAY = 200;

    protected const PER_MEMBER_PER_DAY = 25;

    protected const HIT_SECONDS = 86400;

    protected const MISS_SECONDS = 7 * 86400;

    public function __construct(
        protected Http $http,
        protected Budget $budget,
        protected Cache $cache,
        protected SettingsRepositoryInterface $settings,
        protected LoggerInterface $log
    ) {
    }

    /** The service's short name, for caches, budgets and logs. */
    abstract protected function service(): string;

    /** As the admin sees it, for the log. */
    abstract protected function label(): string;

    abstract protected function setting(): string;

    /**
     * @return array{title: string, name: string}|null
     * @throws LookupException
     */
    abstract protected function ask(string $ean, string $key): ?array;

    public function configured(): bool
    {
        return $this->key() !== '';
    }

    /**
     * The product's name, and the text read for platform and format, or null
     * when the service does not know it, is not set up, or fails.
     *
     * @return array{title: string, name: string}|null
     */
    public function product(string $ean, int $actorId): ?array
    {
        $key = $this->key();

        if ($key === '') {
            return null;
        }

        $service = $this->service();
        // Per key, so a new key is asked afresh and a refused one rests alone.
        $keyed = "curio.$service.".md5($key);
        $known = $this->cache->get("$keyed.$ean");

        // A refused key rests quietly: its refusal was logged.
        if ($known !== null || $this->cache->has("$keyed.refused")) {
            return $known ?: null;
        }

        try {
            $this->budget->spendFor($service, $actorId, static::PER_MEMBER_PER_DAY);
            $this->budget->spend($service, static::PER_SECOND, static::PER_DAY);
            $found = $this->ask($ean, $key);
        } catch (LookupException $e) {
            if (in_array($e->status, [401, 403], true)) {
                $this->cache->put("$keyed.refused", true, 3600);
            }
            $this->failed($service, $this->label(), $e);

            return null;
        }

        $this->cache->put("$keyed.$ean", $found ?? false, $found ? static::HIT_SECONDS : static::MISS_SECONDS);

        return $found;
    }

    protected function key(): string
    {
        return trim((string) $this->settings->get($this->setting()));
    }
}
