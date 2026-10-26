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
 * @property string $conditions
 * @property bool $grading
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Type extends AbstractModel
{
    /** portrait = 2:3 (books, films), box = 3:4 (game boxes), square, landscape = 4:3. */
    public const SHAPES = ['portrait', 'box', 'square', 'landscape'];

    public const SOURCES = ['none', 'books', 'music', 'movies', 'tv', 'games', 'pokemon', 'magic', 'yugioh', 'cards'];

    public const FIELD_KINDS = ['text', 'number', 'select', 'date', 'bool', 'link'];

    /** The condition scales: the general one, and trading cards' (Mint, Near Mint… Damaged). */
    public const CONDITION_SCALES = ['general', 'cards'];

    /**
     * A card printing TCGdex lists that the type's Variant has no choice for:
     * its finish, then foil, stamps or size ("holo.cosmos.trick-or-trade").
     */
    public const PRINTING = '/^(normal|holo|reverse|metal|lenticular)(\.[a-z0-9]+(-[a-z0-9]+)*){0,5}$/';

    protected $table = 'curio_types';

    public $timestamps = true;

    protected $casts = [
        'names' => 'array',
        'fields' => 'array',
        'position' => 'integer',
        'enabled' => 'boolean',
        'progress' => 'boolean',
        'grading' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return list<array{key: string, kind: string, labels: array<string, string>, options?: list<array{key: string, labels: array<string, string>}>}> */
    public function fieldList(): array
    {
        return array_values($this->fields ?? []);
    }

    /** @return list<string> the conditions an item of this type may be in */
    public function conditionList(): array
    {
        return $this->conditions === 'cards' ? Item::CARD_CONDITIONS : Item::CONDITIONS;
    }

    /**
     * Whether a choice field takes this value: one of its options, or for a
     * card's Variant a printing looked up on TCGdex (never one of the
     * options an admin keeps, so they cannot be renamed away).
     */
    public static function allows(array $field, mixed $value): bool
    {
        return in_array($value, array_column($field['options'] ?? [], 'key'), true)
            || ($field['key'] === 'variant' && is_string($value) && strlen($value) <= 100 && preg_match(self::PRINTING, $value));
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

    /** The link detail marked as the member's review ("Read my review" beside the rating). */
    public function reviewField(): ?array
    {
        foreach ($this->fieldList() as $field) {
            if ($field['kind'] === 'link' && ! empty($field['review'])) {
                return $field;
            }
        }

        return null;
    }
}
