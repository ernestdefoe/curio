<?php

use Ernestdefoe\Curio\Type;
use Illuminate\Database\Schema\Builder;

// Cards are never started or completed: card types stop tracking progress.
return [
    'up' => function (Builder $schema) {
        $schema->getConnection()->table('curio_types')
            ->where(fn ($q) => $q->whereIn('source', Type::CARD_SOURCES)->orWhere('conditions', 'cards'))
            ->update(['progress' => false]);
    },
    'down' => function () {
    },
];
