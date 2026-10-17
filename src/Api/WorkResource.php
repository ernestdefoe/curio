<?php

namespace Ernestdefoe\Curio\Api;

use Ernestdefoe\Curio\Storage\Images;
use Ernestdefoe\Curio\Work;
use Ernestdefoe\Curio\WorkText;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Locale\TranslatorInterface;

/**
 * A looked-up title, in the viewer's language: the title, and the description
 * with where it came from (Wikipedia, the source, DeepL, Wikidata).
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
                ->get(fn (Work $work) => $this->text($work)?->description),
            Schema\Str::make('descriptionSource')
                ->nullable()
                ->get(fn (Work $work) => $this->text($work)?->description_source),
            Schema\Str::make('descriptionLocale')
                ->nullable()
                ->get(fn (Work $work) => $this->text($work)?->locale),
            Schema\Str::make('sourceUrl')
                ->nullable()
                ->get(fn (Work $work) => $this->text($work)?->source_url),
            Schema\Integer::make('languages'),
            Schema\Str::make('source'),
        ];
    }
}
