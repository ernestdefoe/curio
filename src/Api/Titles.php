<?php

namespace Ernestdefoe\Curio\Api;

use Ernestdefoe\Curio\Translation\WorkTexts;
use Ernestdefoe\Curio\Work;
use Ernestdefoe\Curio\WorkText;
use Flarum\Locale\TranslatorInterface;

/**
 * Looked-up titles and covers for every item in a response, in two queries
 * however many items there are (lists, notification lists, includes).
 * Getters queue a work id and return a closure; the first closure to run
 * loads everything queued.
 */
class Titles
{
    /** @var array<int, true> */
    private array $queued = [];

    /** @var array<int, array{title: string, cover: ?string, local: ?string}> */
    private array $works = [];

    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function queue(?int $workId): void
    {
        if ($workId && ! isset($this->works[$workId])) {
            $this->queued[$workId] = true;
        }
    }

    /** @return array{title: string, cover: ?string, local: ?string}|null */
    public function work(int $workId): ?array
    {
        $this->queue($workId);
        $this->load();

        return $this->works[$workId] ?? null;
    }

    /** @param iterable<int|null> $workIds */
    public function prime(iterable $workIds): void
    {
        foreach ($workIds as $id) {
            $this->queue($id);
        }
    }

    private function load(): void
    {
        $ids = array_keys($this->queued);
        $this->queued = [];

        if (! $ids) {
            return;
        }

        $locale = WorkTexts::base($this->translator->getLocale());
        $local = [];

        foreach (WorkText::query()->whereIn('work_id', $ids)->whereIn('locale', array_unique([$locale, 'en']))->whereNotNull('title')->get(['work_id', 'locale', 'title']) as $row) {
            if ($row->locale === $locale || ! isset($local[$row->work_id])) {
                $local[$row->work_id] = (string) $row->title;
            }
        }

        foreach (Work::query()->whereIn('id', $ids)->get(['id', 'title', 'cover']) as $work) {
            $this->works[$work->id] = ['title' => $work->title, 'cover' => $work->cover, 'local' => $local[$work->id] ?? null];
        }
    }
}
