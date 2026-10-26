<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * Trading cards are graded on the card scale (Mint, Near Mint, Light
 * Played… Damaged), everything else keeps the general one; and a type can
 * offer a "Graded" tick box. The data follows in the next migration.
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('curio_types', function (Blueprint $table) {
            $table->string('conditions', 10)->default('general');
            $table->boolean('grading')->default(false);
        });
        $schema->table('curio_items', function (Blueprint $table) {
            $table->boolean('graded')->default(false);
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('curio_types', function (Blueprint $table) {
            $table->dropColumn(['conditions', 'grading']);
        });
        $schema->table('curio_items', function (Blueprint $table) {
            $table->dropColumn('graded');
        });
    },
];
