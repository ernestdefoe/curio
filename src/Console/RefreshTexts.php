<?php

namespace Ernestdefoe\Curio\Console;

use Carbon\Carbon;
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
            $query->where('refreshed_at', '<', Carbon::now()->subDays(30))->limit(max(1, (int) $this->input->getOption('limit')));
        }

        $count = 0;
        $query->each(function (Work $work) use ($texts, &$count) {
            $texts->fill($work, null);
            $count++;
        });

        $this->info("Refreshed $count works.");

        return 0;
    }
}
