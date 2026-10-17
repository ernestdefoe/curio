<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $schema->create('curio_collections', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('type_id');
            $table->string('name', 100);
            $table->string('slug', 100);
            $table->text('description')->nullable();
            // public | members | private
            $table->string('visibility', 10)->default('public');
            $table->boolean('wishlist_public')->default(true);
            $table->dateTime('featured_at')->nullable();
            $table->unsignedInteger('position')->default(0);
            // Kept on the collection so cards and the profile never count rows.
            $table->unsignedInteger('owned_count')->default(0);
            $table->unsignedInteger('wish_count')->default(0);
            $table->unsignedInteger('trade_count')->default(0);
            $table->json('preview')->nullable();
            $table->json('facets')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->index(['user_id', 'position'], 'curio_collections_user_index');
            $table->index(['type_id', 'updated_at'], 'curio_collections_type_index');
            $table->index('featured_at', 'curio_collections_featured_index');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('type_id')->references('id')->on('curio_types')->restrictOnDelete();
        });

        $schema->create('curio_items', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('collection_id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('work_id')->nullable();
            $table->string('title', 255);
            $table->string('subtitle', 255)->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            // owned | wishlist | trade
            $table->string('status', 10)->default('owned');
            $table->string('condition', 20)->nullable();
            $table->date('acquired_on')->nullable();
            $table->json('fields')->nullable();
            // The value of the type's first choice field (platform, format…), for filtering and the breakdown.
            $table->string('facet', 100)->nullable();
            $table->text('notes')->nullable();
            $table->json('photos')->nullable();
            $table->string('barcode', 14)->nullable();
            // Only the owner ever sees these.
            $table->decimal('price_paid', 10, 2)->nullable();
            $table->string('price_currency', 3)->nullable();
            $table->boolean('favourite')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->index(['collection_id', 'position'], 'curio_items_collection_index');
            $table->index(['collection_id', 'facet'], 'curio_items_facet_index');
            $table->index(['work_id', 'status'], 'curio_items_work_index');
            $table->index(['user_id', 'status'], 'curio_items_user_index');
            $table->index('created_at', 'curio_items_created_index');
            $table->foreign('collection_id')->references('id')->on('curio_collections')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('work_id')->references('id')->on('curio_works')->nullOnDelete();
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('curio_items');
        $schema->dropIfExists('curio_collections');
    },
];
