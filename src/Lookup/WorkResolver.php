<?php

namespace Ernestdefoe\Curio\Lookup;

use Carbon\Carbon;
use Ernestdefoe\Curio\Storage\Images;
use Ernestdefoe\Curio\Storage\RemoteImage;
use Ernestdefoe\Curio\Translation\WorkTexts;
use Ernestdefoe\Curio\Work;
use Illuminate\Database\QueryException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns a look-up result into a stored work: details from the source, the
 * cover copied onto Curio's disk, and its title and description in every
 * installed language. A work is fetched once and shared by everyone who owns it.
 */
class WorkResolver
{
    public function __construct(
        private Sources $sources,
        private RemoteImage $remote,
        private Images $images,
        private WorkTexts $texts,
        private LoggerInterface $log
    ) {
    }

    /** @throws LookupException */
    public function resolve(string $sourceKey, string $id): Work
    {
        $existing = Work::query()->where('source', $sourceKey)->where('source_id', $id)->first();

        if ($existing) {
            return $existing;
        }

        $source = $this->sources->byKey($sourceKey);

        if (! $source) {
            throw new LookupException("No source $sourceKey here");
        }

        $details = $source->details($id);
        $now = Carbon::now();

        $work = new Work();
        $work->source = $sourceKey;
        $work->source_id = $id;
        $work->title = mb_substr($details['title'], 0, 255);
        $work->creator = $details['creator'] ? mb_substr($details['creator'], 0, 255) : null;
        $work->year = $details['year'] && $details['year'] > 0 && $details['year'] < 3000 ? $details['year'] : null;
        $work->cover = $this->cover($details['cover'] ?? null);
        $work->facts = self::facts($details['facts'] ?? null);
        $work->refreshed_at = $now;
        $work->created_at = $now;

        try {
            $work->save();
        } catch (QueryException $e) {
            // Someone else stored it a moment ago.
            $this->images->delete($work->cover);

            return Work::query()->where('source', $sourceKey)->where('source_id', $id)->firstOrFail();
        }

        $this->texts->fill($work, $details['wikidata'] ?? null, $details['description'] ?? null);

        return $work;
    }

    /**
     * Developer, publisher, release date, platforms and genres, when the source
     * knows them: short lists of short names, nothing else.
     */
    public static function facts(mixed $facts): ?array
    {
        if (! is_array($facts)) {
            return null;
        }

        $clean = [];
        foreach (['developer', 'publisher', 'platforms', 'genres'] as $key) {
            $names = array_values(array_unique(array_filter(array_map(
                fn ($v) => is_string($v) ? mb_substr(trim($v), 0, 80) : '',
                array_slice(is_array($facts[$key] ?? null) ? $facts[$key] : [], 0, 12)
            ))));
            if ($names) {
                $clean[$key] = $names;
            }
        }

        if (is_string($facts['released'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $facts['released'])) {
            $clean['released'] = $facts['released'];
        }

        // A trading card's printing: set, number, rarity and language, short strings only.
        $card = [];
        foreach (['set', 'number', 'rarity', 'language'] as $key) {
            $value = $facts['card'][$key] ?? null;
            if (is_string($value) && ($value = mb_substr(trim($value), 0, 80)) !== '') {
                $card[$key] = $value;
            }
        }
        if ($card) {
            $clean['card'] = $card;
        }

        return $clean ?: null;
    }

    private function cover(?string $url): ?string
    {
        $bytes = $url ? $this->remote->fetch($url) : null;

        if (! $bytes) {
            return null;
        }

        try {
            return $this->images->store($bytes, 'covers');
        } catch (Throwable $e) {
            $this->log->info('[curio] cover not stored: '.$e->getMessage());

            return null;
        }
    }
}
