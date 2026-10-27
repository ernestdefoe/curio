<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

// The order a collection opens in; null keeps the owner's own arrangement.
return [
    'up' => function (Builder $schema) {
        $schema->table('curio_collections', function (Blueprint $table) {
            $table->string('default_sort', 10)->nullable();
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('curio_collections', function (Blueprint $table) {
            $table->dropColumn('default_sort');
        });
    },
];
