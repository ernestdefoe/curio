<?php

namespace Ernestdefoe\Curio\Lookup;

use Carbon\Carbon;
use Ernestdefoe\Curio\Lookup\Sources\Deezer;
use Ernestdefoe\Curio\Lookup\Sources\MusicBrainz;
use Ernestdefoe\Curio\Lookup\Sources\OpenLibrary;
use Ernestdefoe\Curio\Type;
use Illuminate\Database\ConnectionInterface;

/**
 * What a barcode is. The forum's own table answers first, so a box anyone has
 * scanned never leaves the server again. Then: an ISBN is a book (Open
 * Library); a record is asked of Deezer, then MusicBrainz; anything else gets
 * a product name from UPCitemdb, whose platform or format words become the
 * item's details and whose remaining words are searched as a title.
 */
class BarcodeLookup
{
    /** Short names on boxes and in shop listings → the preset option they mean. */
    private const ALIASES = [
        'ps5' => 'playstation-5', 'ps4' => 'playstation-4', 'ps3' => 'playstation-3', 'ps2' => 'playstation-2',
        'switch 2' => 'nintendo-switch-2', 'switch' => 'nintendo-switch', 'xbox series x' => 'xbox-series-x-s',
        '4k ultra hd' => '4k-uhd', 'blu ray' => 'blu-ray', 'bluray' => 'blu-ray', 'vinyl' => 'lp',
        // Discogs writes "2xLP".
        '2xlp' => '2-lp', '3xlp' => '3-lp', 'cass' => 'cassette', 'box set' => 'box-set',
    ];

    private const REGIONS = [
        'ntsc-u' => '/\b(u\.?s\.?|usa|us version|north america)\b/i',
        'ntsc-j' => '/\b(japan|japanese|jpn)\b/i',
        'pal' => '/\b(pal|uk|eu|europe|european)\b/i',
    ];

    public function __construct(
        private ConnectionInterface $db,
        private Sources $sources,
        private OpenLibrary $openLibrary,
        private Deezer $deezer,
        private MusicBrainz $musicBrainz,
        private UpcItemDb $upc
    ) {
    }

    /**
     * @return array{code: string, results: list<array<string, mixed>>, fields: array<string, string>, title: ?string, known: bool}
     * @throws LookupException
     */
    public function lookup(Type $type, string $ean, int $actorId): array
    {
        $answer = ['code' => $ean, 'results' => [], 'fields' => [], 'title' => null, 'known' => false];
        $cached = $this->db->table('curio_barcodes')->where('code', $ean)->first();

        if ($cached) {
            $fields = json_decode((string) $cached->fields, true) ?: [];
            $work = $cached->work_id ? $this->db->table('curio_works')->where('id', $cached->work_id)->first() : null;

            return ['results' => $work ? [['source' => $work->source, 'id' => $work->source_id, 'workId' => (int) $work->id, 'title' => $work->title,
                'creator' => $work->creator, 'year' => $work->year ? (int) $work->year : null, 'cover' => null]] : [],
                'fields' => $fields, 'title' => $cached->title, 'known' => true] + $answer;
        }

        if (Barcode::isIsbn($ean)) {
            $work = $this->openLibrary->workForIsbn($ean);

            return $work ? ['results' => [['source' => 'openlibrary', 'id' => $work] + $this->openLibrary->details($work)]] + $answer : $answer;
        }

        if ($type->source === 'music') {
            if ($album = $this->deezer->albumForBarcode(Barcode::upc($ean)) ?? $this->deezer->albumForBarcode($ean)) {
                return ['results' => [['source' => 'deezer', 'id' => $album] + $this->deezer->details($album)]] + $answer;
            }

            if ($group = $this->musicBrainz->groupForBarcode($ean)) {
                return ['results' => [['source' => 'musicbrainz', 'id' => $group['id']] + $this->musicBrainz->details($group['id'])],
                    'fields' => self::fieldsFrom($type, (string) $group['format'])] + $answer;
            }
        }

        $name = $this->upc->name($ean, $actorId);

        if (! $name) {
            return $answer;
        }

        $fields = self::fieldsFrom($type, $name);
        $title = self::cleanTitle($type, $name, $fields);
        $source = $this->sources->forType($type);
        $results = $source && $title !== '' ? array_slice($source->search($title), 0, 3) : [];

        return ['results' => array_map(fn ($r) => ['source' => $source?->key()] + $r, $results), 'fields' => $fields, 'title' => $title] + $answer;
    }

    /** Remember what a barcode turned out to be. The first answer stays. */
    public function remember(string $ean, ?int $workId, ?string $title, array $fields, string $source): void
    {
        if ($this->db->table('curio_barcodes')->where('code', $ean)->exists()) {
            return;
        }

        $this->db->table('curio_barcodes')->insertOrIgnore([
            'code' => $ean, 'work_id' => $workId, 'title' => $title ? mb_substr($title, 0, 255) : null,
            'fields' => json_encode($fields), 'source' => mb_substr($source, 0, 30), 'created_at' => Carbon::now(),
        ]);
    }

    /**
     * The type's choice fields whose option names appear in a product name.
     *
     * @return array<string, string>
     */
    public static function fieldsFrom(Type $type, string $name): array
    {
        $haystack = ' '.mb_strtolower(preg_replace('/[^\p{L}\p{N}|"]+/u', ' ', $name) ?? '').' ';
        $fields = [];

        foreach ($type->fieldList() as $field) {
            if ($field['kind'] !== 'select') {
                continue;
            }

            $options = [];
            foreach ($field['options'] ?? [] as $option) {
                foreach ($option['labels'] as $label) {
                    $options[mb_strtolower(preg_replace('/[^\p{L}\p{N}|"]+/u', ' ', $label) ?? '')] = $option['key'];
                }
            }
            foreach (self::ALIASES as $alias => $key) {
                $options[$alias] ??= $key;
            }

            // Longest first, so "nintendo switch 2" wins over "nintendo switch".
            uksort($options, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
            $keys = array_column($field['options'] ?? [], 'key');

            foreach ($options as $label => $key) {
                if ($label !== '' && in_array($key, $keys, true) && str_contains($haystack, ' '.trim($label).' ')) {
                    $fields[$field['key']] = $key;
                    break;
                }
            }

            if (! isset($fields[$field['key']]) && $field['key'] === 'region') {
                foreach (self::REGIONS as $key => $pattern) {
                    if (in_array($key, $keys, true) && preg_match($pattern, $name)) {
                        $fields['region'] = $key;
                        break;
                    }
                }
            }
        }

        return $fields;
    }

    /**
     * A shop listing's name without its bracketed notes and the words of the
     * details found in it ("Wii Sports - Nintendo Wii" keeps "Wii Sports").
     *
     * @param array<string, string> $found
     */
    public static function cleanTitle(Type $type, string $name, array $found): string
    {
        $title = preg_replace('/[\[(][^\])]*[\])]/', ' ', $name) ?? $name;
        $words = [];

        foreach ($type->fieldList() as $field) {
            foreach ($field['options'] ?? [] as $option) {
                if (($found[$field['key']] ?? null) !== $option['key']) {
                    continue;
                }
                foreach ($option['labels'] as $label) {
                    $words[] = $label;
                }
                foreach (self::ALIASES as $alias => $key) {
                    if ($key === $option['key']) {
                        $words[] = $alias;
                    }
                }
            }
        }

        usort($words, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        foreach ($words as $word) {
            // Only where it stands apart: after a separator or at the end.
            $title = preg_replace('/(^|[-–|,:]\s*|\s)'.preg_quote($word, '/').'\s*$/iu', ' ', trim($title)) ?? $title;
            $title = preg_replace('/\s[-–|,:]\s*'.preg_quote($word, '/').'\b/iu', ' ', $title) ?? $title;
        }

        $title = preg_replace('/\b(u\.?s\.? version|physical|standard edition)\b/iu', ' ', $title) ?? $title;
        // A maker's name left dangling after the platform went ("… - Nintendo").
        $title = preg_replace('/\s[-–|,:]\s*(nintendo|sony|microsoft|sega)\s*$/iu', ' ', trim($title)) ?? $title;

        return trim(preg_replace('/\s{2,}/u', ' ', $title) ?? '', " -–|,:\t");
    }
}
