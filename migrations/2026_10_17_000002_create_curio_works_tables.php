<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        // A title a look-up found (a game, an album, a book…), shared by every
        // member who owns it.
        $schema->create('curio_works', function (Blueprint $table) {
            $table->increments('id');
            $table->string('source', 30);
            $table->string('source_id', 100);
            $table->string('title', 255);
            $table->string('creator', 255)->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            // A file on Curio's disk, copied at look-up time so a dead CDN never blanks a shelf.
            $table->string('cover', 100)->nullable();
            $table->string('wikidata_id', 20)->nullable();
            $table->unsignedSmallInteger('languages')->default(0);
            $table->dateTime('refreshed_at');
            $table->dateTime('created_at');

            $table->unique(['source', 'source_id'], 'curio_works_source_unique');
            $table->index('wikidata_id', 'curio_works_wikidata_index');
            $table->index('refreshed_at', 'curio_works_refreshed_index');
        });

        // The work's title and description in one language.
        $schema->create('curio_work_texts', function (Blueprint $table) {
            $table->unsignedInteger('work_id');
            $table->string('locale', 10);
            $table->string('title', 255)->nullable();
            $table->text('description')->nullable();
            // wikipedia | wikidata | deepl
            $table->string('description_source', 20)->nullable();
            $table->string('source_url', 500)->nullable();
            $table->dateTime('fetched_at');

            $table->primary(['work_id', 'locale']);
            $table->foreign('work_id')->references('id')->on('curio_works')->cascadeOnDelete();
        });

        // Every barcode the forum has resolved, so a box is only ever looked up once.
        $schema->create('curio_barcodes', function (Blueprint $table) {
            $table->string('code', 14)->primary();
            $table->unsignedInteger('work_id')->nullable();
            $table->string('title', 255)->nullable();
            $table->json('fields')->nullable();
            $table->string('source', 30);
            $table->dateTime('created_at');

            $table->foreign('work_id')->references('id')->on('curio_works')->nullOnDelete();
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('curio_barcodes');
        $schema->dropIfExists('curio_work_texts');
        $schema->dropIfExists('curio_works');
    },
];
