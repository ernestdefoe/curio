<?php

namespace Ernestdefoe\Curio\Api;

use Ernestdefoe\Curio\Collection;
use Ernestdefoe\Curio\Lookup\Sources;
use Ernestdefoe\Curio\Type;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Api\Sort\SortColumn;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Support\Str;
use Tobyz\JsonApiServer\Context as OriginalContext;

/**
 * Collection types. Everyone who may see collections can list them; only
 * admins change them, and a type in use cannot be deleted.
 *
 * @extends AbstractDatabaseResource<Type>
 */
class TypeResource extends AbstractDatabaseResource
{
    public function __construct(private Sources $sources, private TranslatorInterface $translator)
    {
    }

    public function type(): string
    {
        return 'curio-types';
    }

    public function model(): string
    {
        return Type::class;
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->can('ernestdefoe-curio.view')
                ->defaultSort('position'),
            Endpoint\Show::make()
                ->can('ernestdefoe-curio.view'),
            Endpoint\Create::make()
                ->admin(),
            Endpoint\Update::make()
                ->admin(),
            Endpoint\Delete::make()
                ->admin(),
        ];
    }

    public function creating(object $model, OriginalContext $context): ?object
    {
        /** @var Type $model */
        $model->position = (int) Type::query()->max('position') + 1;

        return $model;
    }

    public function deleting(object $model, OriginalContext $context): void
    {
        /** @var Type $model */
        if (Collection::query()->where('type_id', $model->id)->exists()) {
            throw new ValidationException(['type' => $this->translator->trans('ernestdefoe-curio.api.type_in_use')]);
        }
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('slug')
                ->writableOnCreate()
                ->requiredOnCreate()
                ->rule('regex:/^[a-z0-9-]{1,50}$/')
                ->unique('curio_types', 'slug', true),
            Schema\Arr::make('names')
                ->writable()
                ->requiredOnCreate()
                ->rule('array')
                ->rule('min:1')
                ->set(fn (Type $type, $value) => $type->names = self::labels($value, 100)),
            Schema\Str::make('icon')
                ->writable()
                ->rule('regex:/^fa[a-z]? fa-[a-z0-9-]{1,60}( fa-[a-z0-9-]{1,30})?$/'),
            Schema\Str::make('color')
                ->writable()
                ->rule('regex:/^#[0-9a-fA-F]{6}$/'),
            Schema\Str::make('shape')
                ->writable()
                ->in(Type::SHAPES),
            Schema\Str::make('source')
                ->writable()
                ->in(Type::SOURCES),
            Schema\Arr::make('fields')
                ->writable()
                ->rule('array')
                ->rule('max:12')
                ->get(fn (Type $type) => $type->fieldList())
                ->set(fn (Type $type, $value) => $type->fields = self::fieldList($value)),
            Schema\Integer::make('position')
                ->writable(),
            // Whether a title search exists for it here (films, TV and games need Marquee).
            Schema\Boolean::make('searchable')
                ->get(fn (Type $type) => $this->sources->searchable($type)),
        ];
    }

    public function sorts(): array
    {
        return [SortColumn::make('position')];
    }

    /**
     * Labels per language: {"en": "Platform", "fr": "Plateforme"}.
     *
     * @return array<string, string>
     */
    public static function labels(mixed $value, int $max): array
    {
        $labels = [];

        foreach ((array) $value as $locale => $label) {
            if (is_string($locale) && preg_match('/^[a-z]{2,3}(-[A-Za-z]{2,4})?$/', $locale) && is_string($label) && trim($label) !== '') {
                $labels[$locale] = mb_substr(trim($label), 0, $max);
            }
        }

        return $labels;
    }

    /**
     * A type's details, cleaned: unknown kinds dropped, keys made safe and unique.
     *
     * @return list<array<string, mixed>>
     */
    public static function fieldList(mixed $value): array
    {
        $fields = [];
        $seen = [];

        foreach (array_slice((array) $value, 0, 12) as $field) {
            if (! is_array($field) || ! in_array($field['kind'] ?? null, Type::FIELD_KINDS, true)) {
                continue;
            }

            $labels = self::labels($field['labels'] ?? [], 60);
            $key = Str::slug((string) ($field['key'] ?? ($labels['en'] ?? reset($labels) ?: 'field')), '_') ?: 'field';
            $key = mb_substr($key, 0, 30);

            if (! $labels || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $clean = ['key' => $key, 'kind' => $field['kind'], 'labels' => $labels];

            if ($field['kind'] === 'select') {
                $options = [];
                $optionKeys = [];
                foreach (array_slice((array) ($field['options'] ?? []), 0, 60) as $option) {
                    $optionLabels = is_array($option) ? self::labels($option['labels'] ?? [], 60) : [];
                    $optionKey = Str::slug((string) ($option['key'] ?? reset($optionLabels) ?: ''), '-');
                    if ($optionLabels && $optionKey !== '' && ! isset($optionKeys[$optionKey])) {
                        $optionKeys[$optionKey] = true;
                        $options[] = ['key' => mb_substr($optionKey, 0, 40), 'labels' => $optionLabels];
                    }
                }
                $clean['options'] = $options;
            }

            $fields[] = $clean;
        }

        return $fields;
    }
}
