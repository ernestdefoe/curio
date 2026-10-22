<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        // When an item's progress or notes last changed, for the Collections
        // page's "Latest changes" (newest first, so each is indexed).
        $schema->table('curio_items', function (Blueprint $table) {
            $table->dateTime('progress_changed_at')->nullable()->index();
            $table->dateTime('notes_updated_at')->nullable()->index();
        });

        // What is already there dates from the item's last save: the closest known.
        $db = $schema->getConnection();
        $updated = $db->raw($db->getQueryGrammar()->wrap('updated_at'));
        $db->table('curio_items')->whereNotNull('progress')->update(['progress_changed_at' => $updated]);
        $db->table('curio_items')->whereNotNull('notes')->update(['notes_updated_at' => $updated]);
    },
    'down' => function (Builder $schema) {
        $schema->table('curio_items', function (Blueprint $table) {
            $table->dropIndex(['progress_changed_at']);
            $table->dropIndex(['notes_updated_at']);
            $table->dropColumn(['progress_changed_at', 'notes_updated_at']);
        });
    },
];
