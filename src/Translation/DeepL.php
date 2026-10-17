<?php

namespace Ernestdefoe\Curio\Translation;

use Ernestdefoe\Curio\Settings;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Optional: when the admin adds a DeepL key, a description Wikipedia does not
 * have in a language is translated from the English one. Keys ending in ":fx"
 * are DeepL's free plan and use its free endpoint.
 */
class DeepL
{
    private ?Client $http = null;

    public function __construct(private SettingsRepositoryInterface $settings)
    {
    }

    public function configured(): bool
    {
        return $this->key() !== '';
    }

    /** $text in $language, or null when DeepL is not set up or fails. */
    public function translate(string $text, string $language): ?string
    {
        $key = $this->key();

        if ($key === '' || trim($text) === '') {
            return null;
        }

        $host = str_ends_with($key, ':fx') ? 'https://api-free.deepl.com' : 'https://api.deepl.com';
        $this->http ??= new Client(['timeout' => 8, 'connect_timeout' => 3]);

        try {
            $response = $this->http->post("$host/v2/translate", [
                'headers' => ['Authorization' => 'DeepL-Auth-Key '.$key],
                'json' => ['text' => [$text], 'target_lang' => strtoupper($language), 'source_lang' => 'EN'],
            ]);
        } catch (GuzzleException) {
            return null;
        }

        $translated = json_decode((string) $response->getBody(), true)['translations'][0]['text'] ?? null;

        return is_string($translated) && $translated !== '' ? $translated : null;
    }

    private function key(): string
    {
        return trim((string) $this->settings->get(Settings::DEEPL_KEY));
    }
}
