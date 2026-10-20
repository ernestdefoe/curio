<?php

use Carbon\Carbon;
use Illuminate\Database\Schema\Builder;

/*
 * The starting types. Admins rename, reorder, add and remove them; types in use
 * cannot be removed. Labels ship in English and French, and every label can be
 * given in any other installed language from the admin page.
 */

$l = fn (string $en, ?string $fr = null) => array_filter(['en' => $en, 'fr' => $fr]);
$opt = fn (string $key, string $en, ?string $fr = null) => ['key' => $key, 'labels' => $l($en, $fr)];
$plain = fn (string ...$names) => array_map(fn ($n) => $opt(trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $n)), '-'), $n), $names);
$field = fn (string $key, string $kind, array $labels, array $options = []) => ['key' => $key, 'kind' => $kind, 'labels' => $labels] + ($options ? ['options' => $options] : []);

$types = [
    ['games', $l('Video games', 'Jeux vidéo'), 'fas fa-gamepad', '#5b3fd1', 'box', 'games', [
        $field('platform', 'select', $l('Platform', 'Plateforme'), $plain(
            'Nintendo Switch 2', 'Nintendo Switch', 'PlayStation 5', 'PlayStation 4', 'PlayStation 3', 'PlayStation 2', 'PlayStation',
            'Xbox Series X|S', 'Xbox One', 'Xbox 360', 'Xbox', 'PC', 'Wii U', 'Wii', 'GameCube', 'Nintendo 64', 'SNES', 'NES',
            'Game Boy', 'Game Boy Advance', 'Nintendo DS', 'Nintendo 3DS', 'PS Vita', 'PSP', 'Mega Drive / Genesis', 'Saturn', 'Dreamcast'
        )),
        $field('edition', 'select', $l('Edition', 'Édition'), [
            $opt('standard', 'Standard', 'Standard'), $opt('special', 'Special Edition', 'Édition spéciale'),
            $opt('collector', "Collector's Edition", 'Édition collector'), $opt('limited', 'Limited Edition', 'Édition limitée'),
            $opt('deluxe', 'Deluxe Edition', 'Édition Deluxe'), $opt('goty', 'Game of the Year', 'Game of the Year'),
        ]),
        $field('region', 'select', $l('Region', 'Région'), $plain('PAL', 'NTSC-U', 'NTSC-J', 'Region free')),
        $field('cib', 'bool', $l('Complete in box', 'Complet en boîte')),
    ]],
    ['music', $l('Vinyl & CDs', 'Vinyles & CD'), 'fas fa-record-vinyl', '#c2410c', 'square', 'music', [
        $field('format', 'select', $l('Format', 'Format'), $plain('LP', '2×LP', '3×LP', '7"', '10"', '12"', 'CD', 'Cassette', 'Box set')),
        $field('pressing', 'text', $l('Pressing', 'Pressage')),
        $field('label', 'text', $l('Label', 'Label')),
        $field('catalogue', 'text', $l('Catalogue number', 'Numéro de catalogue')),
    ]],
    ['books', $l('Books', 'Livres'), 'fas fa-book', '#0f766e', 'portrait', 'books', [
        $field('format', 'select', $l('Format', 'Format'), [
            $opt('hardback', 'Hardback', 'Relié'), $opt('paperback', 'Paperback', 'Broché'), $opt('pocket', 'Mass market', 'Poche'),
        ]),
        $field('edition', 'text', $l('Edition', 'Édition')),
        $field('signed', 'bool', $l('Signed', 'Dédicacé')),
    ]],
    ['films', $l('Films & TV', 'Films & séries'), 'fas fa-compact-disc', '#1d4ed8', 'portrait', 'movies', [
        $field('format', 'select', $l('Format', 'Format'), $plain('4K UHD', 'Blu-ray', 'DVD', 'VHS', 'LaserDisc')),
        $field('edition', 'select', $l('Edition', 'Édition'), [
            $opt('standard', 'Standard', 'Standard'), $opt('steelbook', 'Steelbook', 'Steelbook'),
            $opt('collector', "Collector's Edition", 'Édition collector'), $opt('limited', 'Limited Edition', 'Édition limitée'),
        ]),
        $field('region', 'select', $l('Region', 'Région'), $plain('A / 1', 'B / 2', 'C / 3', 'Region free')),
    ]],
    ['boardgames', $l('Board games', 'Jeux de société'), 'fas fa-chess-knight', '#15803d', 'square', 'none', [
        $field('edition', 'text', $l('Edition', 'Édition')),
        $field('expansions', 'text', $l('Expansions', 'Extensions')),
        $field('complete', 'bool', $l('Complete', 'Complet')),
    ]],
    ['lego', $l('LEGO & models', 'LEGO & maquettes'), 'fas fa-cubes', '#ca8a04', 'landscape', 'none', [
        $field('set', 'text', $l('Set number', 'Numéro de set')),
        $field('built', 'bool', $l('Built', 'Monté')),
        $field('boxed', 'bool', $l('Boxed', 'En boîte')),
    ]],
    ['cards', $l('Trading cards', 'Cartes à collectionner'), 'fas fa-clone', '#be185d', 'portrait', 'none', [
        $field('set', 'text', $l('Set', 'Extension')),
        $field('number', 'text', $l('Card number', 'Numéro')),
        $field('grade', 'text', $l('Grade', 'Note')),
    ]],
    ['comics', $l('Comics & manga', 'BD & mangas'), 'fas fa-book-open', '#7c3aed', 'portrait', 'none', [
        $field('issue', 'text', $l('Issue', 'Numéro')),
        $field('publisher', 'text', $l('Publisher', 'Éditeur')),
        $field('grade', 'text', $l('Grade', 'Note')),
    ]],
    ['other', $l('Other', 'Autre'), 'fas fa-box-open', '#475569', 'square', 'none', []],
];

return [
    'up' => function (Builder $schema) use ($types) {
        $now = Carbon::now();
        $db = $schema->getConnection();

        foreach ($types as $position => [$slug, $names, $icon, $color, $shape, $source, $fields]) {
            if ($db->table('curio_types')->where('slug', $slug)->exists()) {
                continue;
            }

            $db->table('curio_types')->insert([
                'slug' => $slug, 'names' => json_encode($names), 'icon' => $icon, 'color' => $color, 'shape' => $shape,
                'source' => $source, 'fields' => json_encode($fields), 'position' => $position,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    },
    'down' => function (Builder $schema) {
        // The table goes with the migration that made it.
    },
];
