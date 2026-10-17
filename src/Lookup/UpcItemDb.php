<?php

namespace Ernestdefoe\Curio\Lookup;

use Ernestdefoe\Curio\Settings;
use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Product names by UPC/EAN from UPCitemdb: 100 a day free with no key, more
 * with the admin's paid key. Only asked when nothing else knows the barcode,
 * and every answer is kept by the forum, so most forums stay on the free tier.
 */
class UpcItemDb
{
    public function __construct(private Http $http, private SettingsRepositoryInterface $settings)
    {
    }

    /** The product's name, or null. */
    public function name(string $ean): ?string
    {
        $key = trim((string) $this->settings->get(Settings::UPC_KEY));
        $url = $key !== '' ? 'https://api.upcitemdb.com/prod/v1/lookup' : 'https://api.upcitemdb.com/prod/trial/lookup';
        $headers = $key !== '' ? ['user_key' => $key, 'key_type' => '3scale'] : [];

        $data = $this->http->json('upcitemdb', $url, ['upc' => Barcode::upc($ean)], $headers);
        $title = $data['items'][0]['title'] ?? null;

        return is_string($title) && trim($title) !== '' ? trim($title) : null;
    }
}
