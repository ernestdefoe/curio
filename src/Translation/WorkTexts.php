<?php

namespace Ernestdefoe\Curio\Translation;

use Carbon\Carbon;
use Ernestdefoe\Curio\Lookup\LookupException;
use Ernestdefoe\Curio\Work;
use Ernestdefoe\Curio\WorkText;
use Flarum\Locale\LocaleManager;
use Psr\Log\LoggerInterface;

/**
 * A work's title and description in each language the forum has installed.
 *
 * The title is Wikidata's label in that language. The description is, in
 * order: the Wikipedia article's lead in that language (credited), the
 * source's own description when the source answered in that language, DeepL's
 * translation of the English lead when a key is set, Wikidata's one-line
 * description. Anything that fails leaves what was there before.
 */
class WorkTexts
{
    /** The Wikidata class each source's titles belong to, for finding one by title. */
    private const CLASSES = [
        'deezer' => 'Q482994', 'musicbrainz' => 'Q482994', 'openlibrary' => 'Q7725634',
        'marquee-movie' => 'Q11424', 'marquee-tv' => 'Q5398426', 'marquee-game' => 'Q7889', 'igdb' => 'Q7889',
    ];

    /** Never spend longer than this on one work's languages. */
    private const BUDGET_SECONDS = 10;

    public function __construct(
        private Wikidata $wikidata,
        private DeepL $deepl,
        private LocaleManager $locales,
        private LoggerInterface $log
    ) {
    }

    /** @return list<string> the installed languages as Wikipedia codes (en, fr, pt…) */
    public function languages(): array
    {
        $codes = array_map(fn (string $locale) => self::base($locale), array_keys($this->locales->getLocales()));

        return array_values(array_unique(['en', ...$codes]));
    }

    public static function base(string $locale): string
    {
        $base = strtolower(explode('-', str_replace('_', '-', $locale))[0]);

        return $base === 'nb' ? 'no' : $base;
    }

    /**
     * Fill in the work's texts for $languages (default: every installed one).
     *
     * @param array{0: string, 1: string}|null $link the [property, value] tying the work to Wikidata
     * @param list<string>|null $languages
     */
    public function fill(Work $work, ?array $link, ?string $sourceDescription = null, ?array $languages = null): void
    {
        $languages ??= $this->languages();
        $started = microtime(true);
        $stored = $work->texts()->get()->keyBy('locale');

        try {
            $item = $work->wikidata_id ?? ($link ? $this->wikidata->item($link[0], $link[1]) : null);
            // Not every edition's id is on Wikidata (a remaster, one of many book editions): try the title.
            if (! $item && isset(self::CLASSES[$work->source])) {
                $item = $this->wikidata->find($work->title, $work->creator, $work->year, self::CLASSES[$work->source]);
            }
            $entity = $item ? $this->wikidata->entity($item, $languages) : ['labels' => [], 'descriptions' => [], 'wikis' => []];
        } catch (LookupException $e) {
            $this->log->info('[curio] Wikidata skipped for work '.$work->id.': '.$e->getMessage());

            $item = null;
            $entity = ['labels' => [], 'descriptions' => [], 'wikis' => []];
        }

        if ($item) {
            $work->wikidata_id = $item;
            $work->languages = count($entity['wikis']);
        }

        $english = null;

        // English first: it is the fallback everywhere and what DeepL translates from.
        foreach (array_values(array_unique(['en', ...$languages])) as $language) {
            $text = ['title' => $entity['labels'][$language] ?? $entity['labels']['mul'] ?? null, 'description' => null, 'source' => null, 'url' => null];

            if (isset($entity['wikis'][$language]) && microtime(true) - $started < self::BUDGET_SECONDS) {
                try {
                    $summary = $this->wikidata->summary($language, $entity['wikis'][$language]);
                } catch (LookupException) {
                    $summary = null;
                }

                if ($summary) {
                    $text = ['description' => $summary['extract'], 'source' => 'wikipedia', 'url' => $summary['url']] + $text;
                }
            }

            if ($text['description'] === null && $language === 'en' && $sourceDescription) {
                $text = ['description' => $sourceDescription, 'source' => 'source'] + $text;
            }

            // A fetch that failed this time never wipes an earlier find, and DeepL is paid once.
            $kept = $stored->get($language);
            if ($text['description'] === null && $kept?->description && $kept->description_source !== 'wikidata') {
                $text = ['description' => $kept->description, 'source' => $kept->description_source, 'url' => $kept->source_url] + $text;
            }
            $text['title'] ??= $kept?->title;

            if ($text['description'] === null && $language !== 'en' && $english && microtime(true) - $started < self::BUDGET_SECONDS) {
                $translated = $this->deepl->translate($english, $language);

                if ($translated) {
                    $text = ['description' => $translated, 'source' => 'deepl'] + $text;
                }
            }

            if ($text['description'] === null && isset($entity['descriptions'][$language])) {
                $text = ['description' => $entity['descriptions'][$language], 'source' => 'wikidata'] + $text;
            }

            if ($language === 'en') {
                $english = $text['description'];
            }

            if ($text['title'] === null && $text['description'] === null) {
                continue;
            }

            WorkText::query()->where('work_id', $work->id)->where('locale', $language)->delete();
            WorkText::query()->insert([
                'work_id' => $work->id, 'locale' => $language, 'title' => mb_substr((string) $text['title'], 0, 255) ?: null,
                'description' => $text['description'], 'description_source' => $text['source'],
                'source_url' => $text['url'] ? mb_substr($text['url'], 0, 500) : null, 'fetched_at' => Carbon::now(),
            ]);
        }

        $work->refreshed_at = Carbon::now();
        $work->save();
        $work->unsetRelation('texts');
    }
}
