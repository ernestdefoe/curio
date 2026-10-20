<?php

namespace Ernestdefoe\Curio;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * A kind of collection (video games, vinyl…): its cover shape, the details it
 * asks for and where look-ups come from. Labels are kept per language.
 *
 * @property int $id
 * @property string $slug
 * @property array<string, string> $names
 * @property string $icon
 * @property string $color
 * @property string $shape
 * @property list<array{key: string, kind: string, labels: array<string, string>, options?: list<array{key: string, labels: array<string, string>}>}>|null $fields
 * @property string $source
 * @property int $position
 * @property bool $enabled
 * @property bool $progress
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Type extends AbstractModel
{
    /** portrait = 2:3 (books, films), box = 3:4 (game boxes), square, landscape = 4:3. */
    public const SHAPES = ['portrait', 'box', 'square', 'landscape'];

    public const SOURCES = ['none', 'books', 'music', 'movies', 'tv', 'games'];

    public const FIELD_KINDS = ['text', 'number', 'select', 'date', 'bool'];

    protected $table = 'curio_types';

    public $timestamps = true;

    protected $casts = [
        'names' => 'array',
        'fields' => 'array',
        'position' => 'integer',
        'enabled' => 'boolean',
        'progress' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return list<array{key: string, kind: string, labels: array<string, string>, options?: list<array{key: string, labels: array<string, string>}>}> */
    public function fieldList(): array
    {
        return array_values($this->fields ?? []);
    }

    /** The first choice field, whose value becomes an item's facet (platform, format…). */
    public function facetField(): ?array
    {
        foreach ($this->fieldList() as $field) {
            if ($field['kind'] === 'select') {
                return $field;
            }
        }

        return null;
    }
}
