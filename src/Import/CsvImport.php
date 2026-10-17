<?php

namespace Ernestdefoe\Curio\Import;

use Carbon\Carbon;
use Ernestdefoe\Curio\Api\ItemResource;
use Ernestdefoe\Curio\Item;
use Ernestdefoe\Curio\Lookup\Barcode;
use Ernestdefoe\Curio\Lookup\BarcodeLookup;
use Ernestdefoe\Curio\Type;
use Throwable;

/**
 * Reads a spreadsheet of things into rows Curio can store: its own export,
 * a Discogs collection export, or a Goodreads / StoryGraph library export,
 * told apart by their headers. Covers and texts are found later, in the
 * background (curio:match-works), so a big file imports at once.
 */
class CsvImport
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    private const DISCOGS_CONDITIONS = [
        'mint (m)' => 'mint', 'near mint (nm or m-)' => 'mint', 'very good plus (vg+)' => 'very_good',
        'very good (vg)' => 'very_good', 'good plus (g+)' => 'good', 'good (g)' => 'good', 'fair (f)' => 'fair', 'poor (p)' => 'poor',
    ];

    /**
     * @return array{format: string, rows: list<array<string, mixed>>, skipped: int}
     */
    public function read(string $path, Type $type, int $limit): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return ['format' => 'unknown', 'rows' => [], 'skipped' => 0];
        }

        $header = fgetcsv($handle, 0, ',', '"', '');
        $header = is_array($header) ? array_map(fn ($h) => mb_strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h) ?? '')), $header) : [];
        $format = $this->format($header);

        $rows = [];
        $skipped = 0;

        while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if (count($rows) >= $limit) {
                $skipped++;
                continue;
            }

            $raw = [];
            foreach ($header as $i => $name) {
                $raw[$name] = trim((string) ($cells[$i] ?? ''));
            }

            $row = $format === 'unknown' ? null : $this->{$format}($raw, $type);

            if ($row === null || $row['title'] === '') {
                $skipped++;
                continue;
            }

            $rows[] = $row;
        }

        fclose($handle);

        return ['format' => $format, 'rows' => $rows, 'skipped' => $skipped];
    }

    /** @param list<string> $header */
    private function format(array $header): string
    {
        return match (true) {
            in_array('release_id', $header, true) && in_array('artist', $header, true) => 'discogs',
            in_array('exclusive shelf', $header, true) => 'goodreads',
            in_array('read status', $header, true) && in_array('authors', $header, true) => 'storygraph',
            in_array('title', $header, true) => 'curio',
            default => 'unknown',
        };
    }

    /** Curio's own columns, plus one per detail (by key or by label, in any language). */
    private function curio(array $raw, Type $type): array
    {
        $fields = [];

        foreach ($type->fieldList() as $field) {
            $names = array_unique([$field['key'], ...array_map('mb_strtolower', array_values($field['labels']))]);
            foreach ($names as $name) {
                if (($raw[$name] ?? '') !== '') {
                    $fields[$field['key']] = $this->value($field, $raw[$name]);
                    break;
                }
            }
        }

        return $this->row($type, [
            'title' => $raw['title'] ?? '',
            'subtitle' => $raw['subtitle'] ?? null,
            'year' => $raw['year'] ?? null,
            'status' => $raw['status'] ?? null,
            'condition' => $raw['condition'] ?? null,
            'acquired_on' => $raw['acquired_on'] ?? null,
            'notes' => $raw['notes'] ?? null,
            'barcode' => $raw['barcode'] ?? null,
            'price_paid' => $raw['price_paid'] ?? null,
            'price_currency' => $raw['price_currency'] ?? null,
            'favourite' => in_array(mb_strtolower($raw['favourite'] ?? ''), ['1', 'yes', 'true', 'oui'], true),
        ], $fields);
    }

    private function discogs(array $raw, Type $type): array
    {
        $fields = BarcodeLookup::fieldsFrom($type, $raw['format'] ?? '');

        foreach (['label' => 'label', 'catalogue' => 'catalog#'] as $key => $column) {
            if (($raw[$column] ?? '') !== '' && in_array($key, array_column($type->fieldList(), 'key'), true)) {
                $fields[$key] = $raw[$column];
            }
        }

        return $this->row($type, [
            'title' => $raw['title'] ?? '',
            // Discogs tells same-named artists apart with " (2)".
            'subtitle' => preg_replace('/\s\(\d+\)$/', '', $raw['artist'] ?? ''),
            'year' => $raw['released'] ?? null,
            'condition' => self::DISCOGS_CONDITIONS[mb_strtolower($raw['collection media condition'] ?? '')] ?? null,
            'acquired_on' => $raw['date added'] ?? null,
            'notes' => $raw['collection notes'] ?? null,
        ], $fields);
    }

    private function goodreads(array $raw, Type $type): array
    {
        return $this->row($type, [
            'title' => $raw['title'] ?? '',
            'subtitle' => $raw['author'] ?? null,
            'year' => ($raw['original publication year'] ?? '') ?: ($raw['year published'] ?? null),
            'status' => ($raw['exclusive shelf'] ?? '') === 'to-read' ? 'wishlist' : 'owned',
            'acquired_on' => $raw['date added'] ?? null,
            // Goodreads writes ISBNs as ="978…" so spreadsheets keep the digits.
            'barcode' => preg_replace('/\D/', '', ($raw['isbn13'] ?? '') ?: ($raw['isbn'] ?? '')),
        ], []);
    }

    private function storygraph(array $raw, Type $type): array
    {
        return $this->row($type, [
            'title' => $raw['title'] ?? '',
            'subtitle' => $raw['authors'] ?? null,
            'status' => ($raw['read status'] ?? '') === 'to-read' ? 'wishlist' : 'owned',
            'acquired_on' => $raw['date added'] ?? null,
            'barcode' => preg_replace('/[^\dX]/i', '', $raw['isbn/uid'] ?? ''),
        ], []);
    }

    /** A select field's value from its key or any of its labels. */
    private function value(array $field, string $text): mixed
    {
        if ($field['kind'] !== 'select') {
            return $field['kind'] === 'bool' ? in_array(mb_strtolower($text), ['1', 'yes', 'true', 'oui'], true) : $text;
        }

        foreach ($field['options'] ?? [] as $option) {
            if ($option['key'] === $text || in_array(mb_strtolower($text), array_map('mb_strtolower', $option['labels']), true)) {
                return $option['key'];
            }
        }

        return null;
    }

    /**
     * Cleaned to what an item accepts; anything doubtful is left out rather than guessed.
     *
     * @param array<string, mixed> $v
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function row(Type $type, array $v, array $fields): array
    {
        $year = preg_match('/(\d{4})/', (string) ($v['year'] ?? ''), $m) ? (int) $m[1] : null;
        $price = str_replace(',', '.', (string) ($v['price_paid'] ?? ''));

        try {
            $acquired = ($v['acquired_on'] ?? '') !== '' ? Carbon::parse(str_replace('/', '-', (string) $v['acquired_on'])) : null;
        } catch (Throwable) {
            $acquired = null;
        }

        $status = in_array($v['status'] ?? null, Item::STATUSES, true) ? $v['status'] : 'owned';
        $condition = in_array($v['condition'] ?? null, Item::CONDITIONS, true) ? $v['condition'] : null;

        return [
            'title' => mb_substr(trim((string) $v['title']), 0, 255),
            'subtitle' => ($s = mb_substr(trim((string) ($v['subtitle'] ?? '')), 0, 255)) !== '' ? $s : null,
            'year' => $year && $year < 3000 ? $year : null,
            'status' => $status,
            'condition' => $status === 'wishlist' ? null : $condition,
            'acquired_on' => $acquired && $acquired->isPast() && $status !== 'wishlist' ? $acquired->toDateString() : null,
            'notes' => ($n = mb_substr(trim((string) ($v['notes'] ?? '')), 0, 2000)) !== '' ? $n : null,
            'barcode' => ($v['barcode'] ?? '') !== '' ? Barcode::normalise((string) $v['barcode']) : null,
            'price_paid' => is_numeric($price) && (float) $price >= 0 && (float) $price < 1e8 && $status !== 'wishlist' ? round((float) $price, 2) : null,
            'price_currency' => is_numeric($price) && preg_match('/^[A-Z]{3}$/', (string) ($v['price_currency'] ?? '')) ? $v['price_currency'] : null,
            'favourite' => (bool) ($v['favourite'] ?? false),
            'fields' => ItemResource::cleanFields($type, array_filter($fields, fn ($x) => $x !== null && $x !== '')),
        ];
    }
}
