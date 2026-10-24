<?php

namespace Ernestdefoe\Curio\Lookup;

use Carbon\Carbon;
use Ernestdefoe\Curio\Lookup\Sources\Deezer;
use Ernestdefoe\Curio\Lookup\Sources\MusicBrainz;
use Ernestdefoe\Curio\Lookup\Sources\OpenLibrary;
use Ernestdefoe\Curio\Storage\Images;
use Ernestdefoe\Curio\Type;
use Illuminate\Database\ConnectionInterface;

/**
 * What a barcode is. The forum's own table answers first, so a box anyone has
 * scanned never leaves the server again. Then: an ISBN is a book (Open
 * Library); a record is asked of Deezer, then MusicBrainz; anything else gets
 * a product name from UPCitemdb (strong on North American boxes) and Open
 * Icecat (strong on European ones), asked in the order the barcode's country
 * prefix favours, then eBay when the forum has its own eBay app. The name's
 * platform or format words become the item's details and its remaining words
 * are searched as a title.
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
        // "US" in capitals only: "Among Us" is a title.
        'ntsc-u' => '/\b(U\.?S\.?A?|(?i:usa|us version|north america))\b/',
        // Not "Japanese" alone: Icecat lists every language on the disc.
        'ntsc-j' => '/\b(japan|jpn|japanese (version|import|edition)|import japonais|version japonaise)\b/i',
        'pal' => '/\b(pal|uk|eu|europe|european)\b/i',
    ];

    public function __construct(
        private ConnectionInterface $db,
        private Sources $sources,
        private OpenLibrary $openLibrary,
        private Deezer $deezer,
        private MusicBrainz $musicBrainz,
        private UpcItemDb $upc,
        private Icecat $icecat,
        private Ebay $ebay,
        private Images $images
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
                'creator' => $work->creator, 'year' => $work->year ? (int) $work->year : null, 'cover' => $this->images->url($work->cover, true)]] : [],
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

        $product = $this->product($ean, $actorId);

        if (! $product) {
            return $answer;
        }

        $fields = self::fieldsFrom($type, $product['name']);
        $title = self::cleanTitle($type, $product['title'], self::fieldsFrom($type, $product['title']));
        $source = $this->sources->forType($type);

        return ['results' => $source ? $this->search($source, $title) : [], 'fields' => $fields, 'title' => $title] + $answer;
    }

    /**
     * The product's name from the first service that knows it: UPCitemdb first
     * for US and Canadian barcodes (prefixes 00–13), Open Icecat first for the
     * rest; eBay last, for what both miss. A service that is down or out of
     * today's allowance is skipped; if nobody answered because of that, the
     * member is told to try again.
     *
     * @return array{title: string, name: string}|null
     * @throws LookupException
     */
    private function product(string $ean, int $actorId): ?array
    {
        $upc = function () use ($ean, $actorId) {
            $name = $this->upc->name($ean, $actorId);

            return $name ? ['title' => $name, 'name' => $name] : null;
        };
        $icecat = fn () => $this->icecat->product($ean);
        $down = null;

        foreach ((int) substr($ean, 0, 2) <= 13 ? [$upc, $icecat] : [$icecat, $upc] as $ask) {
            try {
                if ($found = $ask()) {
                    return $found;
                }
            } catch (LookupException $e) {
                $down = $e;
            }
        }

        // eBay, only when set up: never throws, a failure is a miss.
        if ($found = $this->ebay->product($ean)) {
            return $found;
        }

        if ($down && $down->outage) {
            throw $down;
        }

        return null;
    }

    /**
     * The best three for a product's title; a long shop title that finds
     * nothing is tried again without what follows its colon or dash
     * ("Elden Ring: Shadow of the Erdtree" → "Elden Ring").
     *
     * @return list<array<string, mixed>>
     */
    private function search(Source $source, string $title): array
    {
        foreach (array_unique(array_filter([$title, trim(preg_split('/\s[-–:]\s|:\s/u', $title)[0] ?? '')])) as $query) {
            if ($results = array_slice($source->search($query), 0, 3)) {
                return array_map(fn ($r) => ['source' => $source->key()] + $r, $results);
            }
        }

        return [];
    }

    /**
     * Remember what a barcode turned out to be. The first answer stays, except
     * that an entry added by hand (no work) learns its work from the first
     * member who picks one.
     */
    public function remember(string $ean, ?int $workId, ?string $title, array $fields, string $source): void
    {
        $known = $this->db->table('curio_barcodes')->where('code', $ean)->first(['work_id']);

        if ($known) {
            if (! $known->work_id && $workId) {
                $this->db->table('curio_barcodes')->where('code', $ean)->whereNull('work_id')->update([
                    'work_id' => $workId, 'title' => $title ? mb_substr($title, 0, 255) : null, 'source' => mb_substr($source, 0, 30),
                ]);
            }

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
     * Shop-listing words that are not part of a title: what the thing is,
     * which country's copy, and which edition (IGDB and TMDB list the game
     * or film, not the box). English and French, as shops write them.
     */
    private const NOISE = [
        '/\b(video ?games?|jeux? vid[ée]os?|game disc|disque de jeu)\b/iu',
        '/\b(european|europe|eu|uk|us|u\.s\.|usa|fr|french|english|german|asian|international|import|pal|ntsc)\s+(version|edition|import|copy)\b/iu',
        '/\bversion\s+(europ[ée]enne|fran[cç]aise|fr|eu|uk|pal|anglaise|internationale|import)\b/iu',
        '/\b(multilingue|multilingual|multi-?language|mehrsprachig)\b/iu',
        '/\b(standard|deluxe|digital deluxe|gold|ultimate|complete|collector\'?s?|limited|special|launch|day ?one|premium|definitive|anniversary|game of the year|goty|steelbook)\s+edition\b/iu',
        '/\b[ée]dition\s+(standard|deluxe|collector|limit[ée]e|sp[ée]ciale|day ?one|de lancement|gold|ultime|compl[èe]te|premium|anniversaire)\b/iu',
        '/\b(physical|physique)(\s+(edition|copy|version))?\b/iu',
        '/\bu\.?s\.? version\b/iu',
        '/\bPEGI\s*\d+\b/u',
    ];

    /**
     * A shop listing's name without its bracketed notes, the shop's own words
     * (NOISE) and the words of the details found in it ("Wii Sports - Nintendo
     * Wii" keeps "Wii Sports"; "Mario Kart 8 Deluxe - Nintendo Switch Video
     * Game - European Version" keeps "Mario Kart 8 Deluxe").
     *
     * @param array<string, string> $found
     */
    public static function cleanTitle(Type $type, string $name, array $found): string
    {
        $title = preg_replace('/[\[(][^\])]*[\])]/', ' ', $name) ?? $name;
        $title = preg_replace(self::NOISE, ' ', $title) ?? $title;
        $title = self::trimSeparators($title);
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
            $title = self::trimSeparators($title);
        }

        // A lone "Standard" left at the end (Icecat: "… Standard Multilingue PlayStation 5").
        $title = preg_replace('/\s(standard|goty)(?=\s*([-–|,:]|$))/iu', ' ', $title) ?? $title;
        // A maker's name left dangling after the platform went ("… - Nintendo").
        $title = preg_replace('/\s[-–|,:]\s*(nintendo|sony|microsoft|sega)\s*$/iu', ' ', trim($title)) ?? $title;

        return self::trimSeparators($title);
    }

    /** Single spaces, and no dash, bar or colon left hanging at either end or doubled in the middle. */
    private static function trimSeparators(string $title): string
    {
        $title = preg_replace('/\s+[-–|,:](\s+[-–|,:])+\s+/u', ' - ', $title) ?? $title;

        return preg_replace('/^[\s\-–|,:]+|[\s\-–|,:]+$/u', '', preg_replace('/\s{2,}/u', ' ', $title) ?? '') ?? '';
    }
}
