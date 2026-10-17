<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $schema->create('curio_types', function (Blueprint $table) {
            $table->increments('id');
            $table->string('slug', 50)->unique();
            // {"en": "Video games", "fr": "Jeux vidéo"}: one label per installed language.
            $table->json('names');
            $table->string('icon', 100)->default('fas fa-box');
            $table->string('color', 20)->default('#5b3fd1');
            $table->string('shape', 20)->default('portrait');
            // [{key, kind, labels: {en, fr}, options: [{key, labels}]}]
            $table->json('fields')->nullable();
            // Where look-ups come from: none | books | music | movies | tv | games.
            $table->string('source', 20)->default('none');
            $table->unsignedInteger('position')->default(0);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('curio_types');
    },
];
