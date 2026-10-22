<?php

use Illuminate\Database\Schema\Builder;

/*
 * The seeded trading cards type gains Rarity and Language, which the card
 * look-ups fill in. Added only where missing; nothing an admin made is changed.
 */
return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();
        $type = $db->table('curio_types')->where('slug', 'cards')->first(['id', 'fields']);

        if (! $type) {
            return;
        }

        $fields = json_decode((string) $type->fields, true) ?: [];
        $keys = array_column($fields, 'key');
        $added = false;

        foreach ([['rarity', 'Rarity', 'Rareté'], ['language', 'Language', 'Langue']] as [$key, $en, $fr]) {
            if (! in_array($key, $keys, true)) {
                $fields[] = ['key' => $key, 'kind' => 'text', 'labels' => ['en' => $en, 'fr' => $fr]];
                $added = true;
            }
        }

        if ($added) {
            $db->table('curio_types')->where('id', $type->id)->update(['fields' => json_encode($fields)]);
        }
    },
    'down' => function (Builder $schema) {
    },
];
