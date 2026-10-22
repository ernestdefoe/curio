<?php

namespace Ernestdefoe\Curio\Console;

use Carbon\Carbon;
use Ernestdefoe\Curio\Lookup\LookupException;
use Ernestdefoe\Curio\Lookup\WorkResolver;
use Ernestdefoe\Curio\Translation\WorkTexts;
use Ernestdefoe\Curio\Work;
use Flarum\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * Re-reads titles and descriptions from Wikidata and Wikipedia for the works
 * that have gone longest without, a few at a time (scheduled daily), so new
 * languages and improved articles reach the forum without anyone asking.
 */
class RefreshTexts extends AbstractCommand
{
    protected function configure(): void
    {
        $this->setName('curio:refresh-texts')
            ->setDescription('Refresh looked-up titles and descriptions in every installed language.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'How many works to refresh', '25')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Refresh every work, however recent');
    }

    protected function fire(): int
    {
        $texts = resolve(WorkTexts::class);
        $query = Work::query()->orderBy('refreshed_at');

        if (! $this->input->getOption('all')) {
            // Monthly, and daily in its first month for a work Wikidata did not match yet
            // (Wikidata was busy at look-up, or its article came later).
            $query->where(fn ($q) => $q->where('refreshed_at', '<', Carbon::now()->subDays(30))
                ->orWhere(fn ($q) => $q->whereNull('wikidata_id')->where('refreshed_at', '<', Carbon::now()->subDay())->where('created_at', '>', Carbon::now()->subDays(30))))
                ->limit(max(1, (int) $this->input->getOption('limit')));
        }

        $works = resolve(WorkResolver::class);
        $count = 0;
        $query->each(function (Work $work) use ($texts, $works, &$count) {
            if (in_array($work->source, WorkResolver::CARD_SOURCES, true)) {
                try {
                    $works->refreshCard($work);
                } catch (LookupException $e) {
                    // Down for now: next time.
                }
            }
            $texts->fill($work, null);
            $count++;
        });

        $this->info("Refreshed $count works.");

        return 0;
    }
}
