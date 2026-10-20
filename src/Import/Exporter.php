<?php

namespace Ernestdefoe\Curio\Import;

use Ernestdefoe\Curio\Collection;
use Ernestdefoe\Curio\Item;
use Flarum\Settings\SettingsRepositoryInterface;

/** A collection as a spreadsheet, in the columns CsvImport reads back. */
class Exporter
{
    private const COLUMNS = ['title', 'subtitle', 'year', 'status', 'condition', 'acquired_on', 'notes', 'barcode', 'price_paid', 'price_currency', 'favourite', 'rating', 'progress'];

    public function __construct(private SettingsRepositoryInterface $settings)
    {
    }

    public function csv(Collection $collection): string
    {
        $locale = explode('_', explode('-', (string) $this->settings->get('default_locale', 'en'))[0])[0];
        $fields = $collection->type->fieldList();
        $out = fopen('php://temp', 'r+');

        // A byte-order mark, so spreadsheet apps read accents right.
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [...self::COLUMNS, ...array_column($fields, 'key')], ',', '"', '');

        Item::query()->where('collection_id', $collection->id)->orderBy('position')->orderBy('id')
            ->each(function (Item $item) use ($out, $fields, $locale) {
                $row = [
                    $item->title, $item->subtitle, $item->year, $item->status, $item->condition, $item->acquired_on?->toDateString(),
                    $item->notes, $item->barcode, $item->price_paid, $item->price_currency, $item->favourite ? 'yes' : '',
                    $item->rating ? $item->rating / 2 : '', $item->progress,
                ];

                foreach ($fields as $field) {
                    $value = $item->fields[$field['key']] ?? null;
                    if ($field['kind'] === 'select') {
                        foreach ($field['options'] ?? [] as $option) {
                            if ($option['key'] === $value) {
                                $value = $option['labels'][$locale] ?? $option['labels']['en'] ?? reset($option['labels']);
                            }
                        }
                    }
                    $row[] = is_bool($value) ? ($value ? 'yes' : '') : $value;
                }

                fputcsv($out, array_map(self::cell(...), $row), ',', '"', '');
            });

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /** A cell a spreadsheet will not run as a formula. */
    private static function cell(mixed $value): string
    {
        $text = (string) $value;

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($text) ? "'".$text : $text;
    }
}
