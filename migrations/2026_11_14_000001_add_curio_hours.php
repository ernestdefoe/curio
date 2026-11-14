<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        // A type can track the hours spent on each item (hours played, for games).
        $schema->table('curio_types', function (Blueprint $table) {
            $table->boolean('hours')->default(false);
        });

        $schema->table('curio_items', function (Blueprint $table) {
            $table->decimal('hours', 8, 1)->nullable();
        });

        // The seeded Video games type starts with it on.
        $schema->getConnection()->table('curio_types')->where('slug', 'games')->update(['hours' => true]);
    },
    'down' => function (Builder $schema) {
        $schema->table('curio_types', function (Blueprint $table) {
            $table->dropColumn('hours');
        });
        $schema->table('curio_items', function (Blueprint $table) {
            $table->dropColumn('hours');
        });
    },
];
