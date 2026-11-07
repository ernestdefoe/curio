<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

// The game an edition or DLC belongs to (its id in the same source and its
// title), so Show it off finds the game's discussion. Null for a game.
return [
    'up' => function (Builder $schema) {
        $schema->table('curio_works', function (Blueprint $table) {
            $table->string('base_id', 20)->nullable();
            $table->string('base_title')->nullable();
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('curio_works', function (Blueprint $table) {
            $table->dropColumn(['base_id', 'base_title']);
        });
    },
];
