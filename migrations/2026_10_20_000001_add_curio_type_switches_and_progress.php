<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        // A type can be switched off (built in the background, or set aside),
        // and can track each item's progress: not started, in progress, completed.
        $schema->table('curio_types', function (Blueprint $table) {
            $table->boolean('enabled')->default(true);
            $table->boolean('progress')->default(false);
        });

        $schema->table('curio_items', function (Blueprint $table) {
            $table->string('progress', 12)->nullable();
        });

        // The seeded types that are played, read or watched start with it on.
        $schema->getConnection()->table('curio_types')->whereIn('slug', ['games', 'books', 'films', 'comics'])->update(['progress' => true]);
    },
    'down' => function (Builder $schema) {
        $schema->table('curio_types', function (Blueprint $table) {
            $table->dropColumn(['enabled', 'progress']);
        });
        $schema->table('curio_items', function (Blueprint $table) {
            $table->dropColumn('progress');
        });
    },
];
