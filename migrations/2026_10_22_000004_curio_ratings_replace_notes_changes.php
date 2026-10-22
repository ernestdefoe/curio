<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        // "Latest changes" shows ratings instead of notes: when each item was
        // last rated, newest first.
        $schema->table('curio_items', function (Blueprint $table) {
            $table->dateTime('rating_changed_at')->nullable()->index();
        });

        $db = $schema->getConnection();
        $db->table('curio_items')->whereNotNull('rating')
            ->update(['rating_changed_at' => $db->raw($db->getQueryGrammar()->wrap('updated_at'))]);

        $schema->table('curio_items', function (Blueprint $table) {
            $table->dropIndex(['notes_updated_at']);
            $table->dropColumn('notes_updated_at');
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('curio_items', function (Blueprint $table) {
            $table->dateTime('notes_updated_at')->nullable()->index();
        });

        $schema->table('curio_items', function (Blueprint $table) {
            $table->dropIndex(['rating_changed_at']);
            $table->dropColumn('rating_changed_at');
        });
    },
];
