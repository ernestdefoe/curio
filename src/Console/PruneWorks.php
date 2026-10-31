<?php

namespace Ernestdefoe\Curio\Console;

use Ernestdefoe\Curio\Lookup\WorkPruner;
use Flarum\Console\AbstractCommand;

/**
 * Deletes works no item uses: look-ups never added, and works whose last item
 * went with a deleted collection or member, or before Curio 1.22.1. A work an
 * item lets go of is deleted straight away.
 */
class PruneWorks extends AbstractCommand
{
    public function __construct(private WorkPruner $works)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('curio:prune')
            ->setDescription('Delete looked-up works no item uses any more.');
    }

    protected function fire(): int
    {
        $this->info('Deleted '.$this->works->prune().' works.');

        return 0;
    }
}
