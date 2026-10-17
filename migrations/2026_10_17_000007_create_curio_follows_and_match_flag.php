<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        // Members following a collection, to hear when it grows.
        $schema->create('curio_follows', function (Blueprint $table) {
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('collection_id');
            $table->dateTime('created_at');

            $table->primary(['user_id', 'collection_id']);
            $table->index('collection_id', 'curio_follows_collection_index');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('collection_id')->references('id')->on('curio_collections')->cascadeOnDelete();
        });

        // Imported items wait here for a cover and texts, matched in the background.
        $schema->table('curio_items', function (Blueprint $table) {
            $table->boolean('needs_match')->default(false);
            $table->index('needs_match', 'curio_items_needs_match_index');
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('curio_follows');
        $schema->table('curio_items', function (Blueprint $table) {
            $table->dropIndex('curio_items_needs_match_index');
            $table->dropColumn('needs_match');
        });
    },
];
