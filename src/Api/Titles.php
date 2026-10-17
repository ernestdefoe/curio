<?php

namespace Ernestdefoe\Curio\Api;

use Ernestdefoe\Curio\Translation\WorkTexts;
use Ernestdefoe\Curio\WorkText;
use Flarum\Locale\TranslatorInterface;

/**
 * Looked-up titles in the viewer's language for a page of items, fetched in
 * one query (titles only; descriptions load with a single item).
 */
class Titles
{
    /** @var array<int, string> work id → title in the viewer's language */
    private array $titles = [];

    public function __construct(private TranslatorInterface $translator)
    {
    }

    /** @param iterable<int|null> $workIds */
    public function prime(iterable $workIds): void
    {
        $ids = array_values(array_unique(array_filter(is_array($workIds) ? $workIds : iterator_to_array($workIds))));
        $ids = array_diff($ids, array_keys($this->titles));

        if (! $ids) {
            return;
        }

        $locale = WorkTexts::base($this->translator->getLocale());
        $rows = WorkText::query()->whereIn('work_id', $ids)->whereIn('locale', array_unique([$locale, 'en']))->whereNotNull('title')
            ->get(['work_id', 'locale', 'title']);

        foreach ($rows as $row) {
            if ($row->locale === $locale || ! isset($this->titles[$row->work_id])) {
                $this->titles[$row->work_id] = (string) $row->title;
            }
        }
    }

    public function get(int $workId): ?string
    {
        if (! array_key_exists($workId, $this->titles)) {
            $this->prime([$workId]);
        }

        return $this->titles[$workId] ?? null;
    }
}
