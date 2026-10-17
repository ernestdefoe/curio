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
