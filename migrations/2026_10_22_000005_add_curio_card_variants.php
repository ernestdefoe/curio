<?php

use Illuminate\Database\Schema\Builder;

/*
 * The seeded trading cards type gains Variant (normal, reverse holo, holo,
 * 1st edition, foil…), which the card look-ups offer per card; added after
 * the card number, only where missing.
 *
 * Cards looked up before named their set as their creator. That line is
 * emptied (on the card and on items that copied it) and the cards are marked
 * so curio:match-works reads their artist and finishes back, a few at a time.
 */
return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();
        $type = $db->table('curio_types')->where('slug', 'cards')->first(['id', 'fields']);
        $fields = $type ? (json_decode((string) $type->fields, true) ?: []) : [];

        if ($type && ! in_array('variant', array_column($fields, 'key'), true)) {
            $opt = fn (string $key, string $en, string $fr) => ['key' => $key, 'labels' => ['en' => $en, 'fr' => $fr]];
            $variant = ['key' => 'variant', 'kind' => 'select', 'labels' => ['en' => 'Variant', 'fr' => 'Variante'], 'options' => [
                $opt('normal', 'Normal', 'Normale'), $opt('reverse', 'Reverse holo', 'Reverse'), $opt('holo', 'Holo', 'Holo'),
                $opt('first_edition', '1st edition', '1re édition'), $opt('w_promo', 'W promo stamp', 'Tampon W promo'),
                $opt('foil', 'Foil', 'Foil'), $opt('etched', 'Etched foil', 'Foil gravé'),
            ]];
            $at = array_search('number', array_column($fields, 'key'), true);
            array_splice($fields, $at === false ? count($fields) : $at + 1, 0, [$variant]);
            $db->table('curio_types')->where('id', $type->id)->update(['fields' => json_encode($fields)]);
        }

        $db->table('curio_works')->whereIn('source', ['tcgdex', 'scryfall', 'ygoprodeck'])->orderBy('id')->select(['id', 'source', 'creator', 'facts'])
            ->chunk(200, function ($works) use ($db) {
                foreach ($works as $work) {
                    $set = json_decode((string) $work->facts, true)['card']['set'] ?? null;
                    $changes = $work->source === 'ygoprodeck' ? [] : ['refreshed_at' => '2000-01-01 00:00:00'];

                    if ($work->creator !== null && $work->creator === $set) {
                        $changes['creator'] = null;
                        $db->table('curio_items')->where('work_id', $work->id)->where('subtitle', $set)->update(['subtitle' => null]);
                    }
                    if ($changes) {
                        $db->table('curio_works')->where('id', $work->id)->update($changes);
                    }
                }
            });
    },
    'down' => function (Builder $schema) {
    },
];
