<?php

use Illuminate\Database\Schema\Builder;

/*
 * Game boxes and IGDB covers are 3:4. The seeded "Video games" type now uses
 * that shape. An existing forum's type moves only while it is exactly as it
 * was seeded: never saved (updated_at = created_at), still portrait, with its
 * seeded icon and source. Anything an admin touched is left alone.
 */
return [
    'up' => function (Builder $schema) {
        $schema->getConnection()->table('curio_types')
            ->where(['slug' => 'games', 'shape' => 'portrait', 'source' => 'games', 'icon' => 'fas fa-gamepad'])
            ->whereColumn('updated_at', 'created_at')
            ->update(['shape' => 'box']);
    },
    'down' => function (Builder $schema) {
        // Left as it is: the admin may have chosen 3:4 since.
    },
];
