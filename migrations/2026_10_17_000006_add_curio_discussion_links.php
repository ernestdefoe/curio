<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        // The item a "Show it off" discussion is about…
        $schema->table('discussions', function (Blueprint $table) {
            $table->unsignedInteger('curio_item_id')->nullable();
            $table->index('curio_item_id', 'discussions_curio_item_index');
        });

        // …and the discussion an item was shown off in.
        $schema->table('curio_items', function (Blueprint $table) {
            $table->unsignedInteger('discussion_id')->nullable();
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('discussions', function (Blueprint $table) {
            $table->dropIndex('discussions_curio_item_index');
            $table->dropColumn('curio_item_id');
        });
        $schema->table('curio_items', function (Blueprint $table) {
            $table->dropColumn('discussion_id');
        });
    },
];
