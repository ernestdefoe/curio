<?php

namespace Ernestdefoe\Curio\Lookup;

use Ernestdefoe\Curio\Settings;

/**
 * Product names from EAN-Search.org (paid; about 1.3 billion barcodes, plans
 * from €19 a month for 5,000 queries, a €1 trial month of 100). Every query
 * counts, found or not, so names are kept a month and misses a week. Asked
 * after the free services and barcodelookup.com, in the forum's language when
 * EAN-Search has it.
 */
class EanSearch extends KeyedBarcodeSource
{
    private const URL = 'https://api.ean-search.org/api';

    protected const HIT_SECONDS = 30 * 86400;

    /** Forum language → EAN-Search's language number; anything else asks for any language (99). */
    private const LANGUAGES = ['en' => 1, 'da' => 2, 'de' => 3, 'es' => 4, 'fi' => 5, 'fr' => 6, 'it' => 8, 'nl' => 10, 'no' => 11, 'nb' => 11, 'pl' => 12, 'pt' => 13, 'sv' => 15];

    protected function service(): string
    {
        return 'eansearch';
    }

    protected function label(): string
    {
        return 'EAN-Search';
    }

    protected function setting(): string
    {
        return Settings::EANSEARCH_TOKEN;
    }

    protected function ask(string $ean, string $key): ?array
    {
        $locale = strtolower(explode('_', str_replace('-', '_', (string) $this->settings->get('default_locale')))[0]);
        $data = $this->http->json('eansearch', self::URL, [
            'token' => $key, 'op' => 'barcode-lookup', 'format' => 'json', 'ean' => $ean, 'language' => self::LANGUAGES[$locale] ?? 99,
        ]);
        $product = $data[0] ?? null;

        if (! is_array($product)) {
            throw new LookupException('eansearch answered with something other than a product');
        }

        if (isset($product['error'])) {
            if ($product['error'] === 'Barcode not found') {
                return null;
            }

            // Their own words ("Invalid token", a spent quota): never the token itself.
            $said = mb_substr(preg_replace('/[^\p{L}\p{N} .,-]+/u', '', (string) $product['error']) ?? '', 0, 60);

            throw new LookupException("eansearch said: $said", false, false, stripos($said, 'token') !== false ? 401 : null);
        }

        $name = is_string($product['name'] ?? null) ? trim($product['name']) : '';

        return $name !== '' ? ['title' => mb_substr($name, 0, 200), 'name' => mb_substr($name, 0, 255)] : null;
    }
}
