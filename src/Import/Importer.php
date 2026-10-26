<?php

namespace Ernestdefoe\Curio\Import;

use Carbon\Carbon;
use Ernestdefoe\Curio\Collection;
use Ernestdefoe\Curio\CollectionStats;
use Ernestdefoe\Curio\Item;
use Ernestdefoe\Curio\Job\NotifyFollowers;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\UploadedFileInterface;

/** A spreadsheet into a collection: checked, read, stored in one transaction, matched later. */
class Importer
{
    /** Imports are heavy; a member gets a few an hour. */
    private const PER_HOUR = 5;

    public function __construct(
        private CsvImport $csv,
        private CollectionStats $stats,
        private ConnectionInterface $db,
        private Queue $queue,
        private Cache $cache,
        private TranslatorInterface $translator
    ) {
    }

    /** @return array{format: string, imported: int, skipped: int} */
    public function import(Collection $collection, ?UploadedFileInterface $file, User $actor): array
    {
        $fail = fn (string $key, array $params = []) => throw new ValidationException(['file' => $this->translator->trans("ernestdefoe-curio.api.import_$key", $params)]);

        if (! $file || $file->getError() !== UPLOAD_ERR_OK) {
            $fail('missing');
        }

        if (($file->getSize() ?? PHP_INT_MAX) > CsvImport::MAX_BYTES) {
            $fail('too_big', ['size' => '2 MB']);
        }

        $key = "curio.imports.$actor->id";
        $this->cache->add($key, 0, 3600);
        if (! $actor->isAdmin() && $this->cache->increment($key) > self::PER_HOUR) {
            $fail('too_often');
        }

        $room = Collection::MAX_ITEMS - Item::query()->where('collection_id', $collection->id)->count();
        $read = $this->csv->read((string) $file->getStream()->getMetadata('uri'), $collection->type, max(0, $room));

        if ($read['format'] === 'unknown') {
            $fail('unknown_format');
        }

        $facet = $collection->type->facetField();
        $position = (int) Item::query()->where('collection_id', $collection->id)->max('position');
        $now = Carbon::now();

        $this->db->transaction(function () use ($read, $collection, $facet, &$position, $now) {
            foreach ($read['rows'] as $row) {
                $item = new Item();
                $item->collection_id = $collection->id;
                $item->user_id = $collection->user_id;
                $item->title = $row['title'];
                $item->subtitle = $row['subtitle'];
                $item->year = $row['year'];
                $item->status = $row['status'];
                $item->condition = $row['condition'];
                $item->acquired_on = $row['acquired_on'];
                $item->notes = $row['notes'];
                $item->barcode = $row['barcode'];
                $item->price_paid = $row['price_paid'] !== null ? (string) $row['price_paid'] : null;
                $item->price_currency = $row['price_currency'];
                $item->favourite = $row['favourite'];
                $item->rating = $row['rating'];
                $item->progress = $row['progress'];
                $item->graded = $row['graded'];
                $item->fields = $row['fields'];
                $item->facet = $facet ? ($row['fields'][$facet['key']] ?? null) : null;
                $item->position = ++$position;
                $item->needs_match = true;
                $item->created_at = $now;
                $item->save();
            }
        });

        $this->stats->refresh($collection);

        if (count($read['rows'])) {
            $this->queue->push(new NotifyFollowers($collection->id));
        }

        return ['format' => $read['format'], 'imported' => count($read['rows']), 'skipped' => $read['skipped']];
    }
}
