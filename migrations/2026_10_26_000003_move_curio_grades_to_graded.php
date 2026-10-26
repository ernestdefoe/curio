<?php

use Illuminate\Database\Schema\Builder;

/*
 * Card types take the card condition scale; cards, comics and games offer
 * the Graded tick box.
 *
 * The seeded cards and comics types asked for a free-text "Grade". It goes:
 * an item that had one is marked Graded, and its words move to the end of
 * its notes, so nothing a member typed is lost. Cards already given a
 * general condition get the nearest card one.
 */
return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();
        $db->table('curio_types')->where('slug', 'cards')->orWhereIn('source', ['pokemon', 'magic', 'yugioh', 'cards'])->update(['conditions' => 'cards']);
        $db->table('curio_types')->whereIn('slug', ['cards', 'comics', 'games'])->update(['grading' => true]);

        $cardTypes = $db->table('curio_types')->where('conditions', 'cards')->pluck('id')->all();
        $collections = $db->table('curio_collections')->whereIn('type_id', $cardTypes)->select('id');
        foreach (['sealed' => 'mint', 'very_good' => 'near_mint', 'good' => 'light_played', 'fair' => 'moderately_played', 'poor' => 'heavily_played'] as $from => $to) {
            $db->table('curio_items')->whereIn('collection_id', $collections)->where('condition', $from)->update(['condition' => $to]);
        }

        $locale = explode('_', str_replace('-', '_', (string) $db->table('settings')->where('key', 'default_locale')->value('value') ?: 'en'))[0];

        foreach ($db->table('curio_types')->whereIn('slug', ['cards', 'comics'])->get(['id', 'fields']) as $type) {
            $fields = json_decode((string) $type->fields, true) ?: [];
            $at = array_search('grade', array_column($fields, 'key'), true);
            if ($at === false || ($fields[$at]['kind'] ?? null) !== 'text') {
                continue;
            }
            $labels = (array) ($fields[$at]['labels'] ?? []);
            $word = $labels[$locale] ?? $labels['en'] ?? 'Grade';
            array_splice($fields, $at, 1);
            $db->table('curio_types')->where('id', $type->id)->update(['fields' => json_encode($fields)]);

            $db->table('curio_items')->whereIn('collection_id', $db->table('curio_collections')->where('type_id', $type->id)->select('id'))
                ->where('fields', 'like', '%"grade"%')->select(['id', 'fields', 'notes'])
                ->chunkById(200, function ($items) use ($db, $word) {
                    foreach ($items as $item) {
                        $values = json_decode((string) $item->fields, true) ?: [];
                        $grade = is_scalar($values['grade'] ?? null) ? trim((string) $values['grade']) : '';
                        unset($values['grade']);
                        $changes = ['fields' => json_encode((object) $values)];
                        if ($grade !== '') {
                            $line = "$word: $grade";
                            $changes['graded'] = true;
                            $changes['notes'] = mb_substr($item->notes ? rtrim($item->notes)."\n".$line : $line, 0, 2000);
                        }
                        $db->table('curio_items')->where('id', $item->id)->update($changes);
                    }
                });
        }

        // Cards read again from TCGdex by curio:match-works, a few a run, for
        // their printings (Trick or Trade stamps, cosmos holos…).
        $db->table('curio_works')->where('source', 'tcgdex')->update(['refreshed_at' => '2000-01-01 00:00:00']);
    },
    'down' => function (Builder $schema) {
    },
];
