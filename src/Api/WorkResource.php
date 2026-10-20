<?php

namespace Ernestdefoe\Curio\Api;

use Ernestdefoe\Curio\Storage\Images;
use Ernestdefoe\Curio\Translation\WorkTexts;
use Ernestdefoe\Curio\Work;
use Ernestdefoe\Curio\WorkText;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Locale\TranslatorInterface;

/**
 * A looked-up title, in the viewer's language: the title, and the description
 * with where it came from (Wikipedia, the source, DeepL, Wikidata). The
 * description is only ever in the viewer's language, and Wikidata's one-liner
 * ("2024 video game") is not one. Without one, the English description comes
 * separately as `fallback`.
 *
 * @extends AbstractDatabaseResource<Work>
 */
class WorkResource extends AbstractDatabaseResource
{
    public function __construct(private Images $images, private TranslatorInterface $translator)
    {
    }

    public function type(): string
    {
        return 'curio-works';
    }

    public function model(): string
    {
        return Work::class;
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Show::make()
                ->can('ernestdefoe-curio.view'),
        ];
    }

    private function text(Work $work): ?WorkText
    {
        return $work->textFor($this->translator->getLocale());
    }

    /** The viewer's own language, when it has a description (Wikidata's one-liner is not one). */
    private function local(Work $work): ?WorkText
    {
        $text = $work->texts->firstWhere('locale', WorkTexts::base($this->translator->getLocale()));

        return $text?->description && $text->description_source !== 'wikidata' ? $text : null;
    }

    /** @return array{description: string, source: ?string, url: ?string, locale: string}|null */
    private function fallback(Work $work): ?array
    {
        $en = $work->texts->firstWhere('locale', 'en');

        if ($this->local($work) || ! $en?->description || $en->description_source === 'wikidata') {
            return null;
        }

        return ['description' => $en->description, 'source' => $en->description_source, 'url' => $en->source_url, 'locale' => 'en'];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('title')
                ->get(fn (Work $work) => $this->text($work)->title ?? $work->title),
            Schema\Str::make('originalTitle')
                ->property('title'),
            Schema\Str::make('creator')->nullable(),
            Schema\Integer::make('year')->nullable(),
            Schema\Str::make('coverUrl')
                ->nullable()
                ->get(fn (Work $work) => $this->images->url($work->cover)),
            Schema\Str::make('description')
                ->nullable()
                ->get(fn (Work $work) => $this->local($work)?->description),
            Schema\Str::make('descriptionSource')
                ->nullable()
                ->get(fn (Work $work) => $this->local($work)?->description_source),
            Schema\Str::make('descriptionLocale')
                ->nullable()
                ->get(fn (Work $work) => $this->local($work)?->locale),
            Schema\Str::make('sourceUrl')
                ->nullable()
                ->get(fn (Work $work) => $this->local($work)?->source_url),
            Schema\Arr::make('fallback')
                ->nullable()
                ->get(fn (Work $work) => $this->fallback($work)),
            Schema\Integer::make('languages'),
            // Developer, publisher, release date, platforms, genres (games looked up on IGDB).
            Schema\Arr::make('facts')
                ->nullable(),
            Schema\Str::make('source'),
        ];
    }
}
