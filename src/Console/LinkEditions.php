<?php

namespace Ernestdefoe\Curio\Console;

use Ernestdefoe\Curio\Lookup\GameBases;
use Ernestdefoe\Curio\Work;
use Flarum\Console\AbstractCommand;

/**
 * Finds the game each stored edition or DLC belongs to (scheduled hourly):
 * games stored before Curio 1.23, and any whose look-up found IGDB down. One
 * IGDB query per 500 games; a game with no base is marked '' so it is asked
 * once.
 */
class LinkEditions extends AbstractCommand
{
    public function __construct(private GameBases $bases)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('curio:link-editions')
            ->setDescription('Link game editions and DLC to their game, for Show it off.');
    }

    protected function fire(): int
    {
        $linked = 0;

        foreach (['igdb', 'marquee-game'] as $source) {
            $works = Work::query()->where('source', $source)->whereNull('base_id')->orderBy('id')->limit(500)->get(['id', 'source_id']);
            $bases = $works->isEmpty() ? null : $this->bases->for($source, $works->pluck('source_id')->all());

            if ($bases === null) {
                continue;
            }

            foreach ($works as $work) {
                $base = $bases[$work->source_id] ?? null;
                $linked += $base ? 1 : 0;
                Work::query()->whereKey($work->id)->update(['base_id' => $base['id'] ?? '', 'base_title' => $base['title'] ?? null]);
            }
        }

        $this->info("Linked $linked editions and DLC to their game.");

        return 0;
    }
}
