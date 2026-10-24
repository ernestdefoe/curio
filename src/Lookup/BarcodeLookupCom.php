<?php

namespace Ernestdefoe\Curio\Lookup;

use Ernestdefoe\Curio\Settings;

/**
 * Product names from barcodelookup.com (paid; plans from $99 a month for
 * 5,000 answers, a call that finds nothing is not counted). Asked after the
 * free services. Only the product's title and format are read: its images
 * and descriptions belong to third parties under barcodelookup.com's terms,
 * so they are never shown or kept, and a name is kept a day only (the terms
 * ask for every kept answer to go when the subscription ends).
 */
class BarcodeLookupCom extends KeyedBarcodeSource
{
    private const URL = 'https://api.barcodelookup.com/v3/products';

    protected function service(): string
    {
        return 'barcodelookup';
    }

    protected function label(): string
    {
        return 'barcodelookup.com';
    }

    protected function setting(): string
    {
        return Settings::BARCODELOOKUP_KEY;
    }

    protected function ask(string $ean, string $key): ?array
    {
        // 404 = "No data returned": Http answers it with [].
        $product = $this->http->json('barcodelookup', self::URL, ['barcode' => $ean, 'key' => $key])['products'][0] ?? null;
        $title = is_array($product) && is_string($product['title'] ?? null) ? trim($product['title']) : '';

        if ($title === '') {
            return null;
        }

        $format = is_string($product['format'] ?? null) ? trim($product['format']) : '';

        return ['title' => mb_substr($title, 0, 200), 'name' => mb_substr(trim("$title $format"), 0, 255)];
    }
}
