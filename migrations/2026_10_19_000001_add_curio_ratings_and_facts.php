<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        // The owner's rating in half stars: 1 (½ ★) to 10 (★★★★★).
        $schema->table('curio_items', function (Blueprint $table) {
            $table->unsignedTinyInteger('rating')->nullable();
        });

        // A looked-up game's developer, publisher, release date, platforms and genres.
        $schema->table('curio_works', function (Blueprint $table) {
            $table->json('facts')->nullable();
        });

        // The "Recently added" widget is now off until an admin turns it on. A
        // forum that already placed it in Bespoke keeps it.
        $db = $schema->getConnection();
        if ($db->table('settings')->where('key', 'like', 'bespoke%')->where('value', 'like', '%curio-recent%')->exists()) {
            $db->table('settings')->updateOrInsert(['key' => 'ernestdefoe-curio.widget'], ['value' => '1']);
        }
    },
    'down' => function (Builder $schema) {
        $schema->table('curio_items', function (Blueprint $table) {
            $table->dropColumn('rating');
        });
        $schema->table('curio_works', function (Blueprint $table) {
            $table->dropColumn('facts');
        });
    },
];
