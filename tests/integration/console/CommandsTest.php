<?php

namespace Ernestdefoe\Curio\Tests\integration\console;

use Carbon\Carbon;
use Ernestdefoe\Curio\Lookup\GameBases;
use Flarum\Testing\integration\ConsoleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The scheduled and one-off commands, built by the container as the console
 * builds them (their services come in through the constructor).
 */
class CommandsTest extends ConsoleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'ernestdefoe-curio');
    }

    #[Test]
    public function editions_and_dlc_are_linked_to_their_game_a_game_at_a_time(): void
    {
        $now = Carbon::now();
        $this->prepareDatabase([
            'curio_works' => [
                ['id' => 1, 'source' => 'igdb', 'source_id' => '901', 'title' => 'Pragmata Deluxe', 'refreshed_at' => $now, 'created_at' => $now, 'languages' => 0],
                ['id' => 2, 'source' => 'igdb', 'source_id' => '902', 'title' => 'Pragmata: Expansion Pass', 'refreshed_at' => $now, 'created_at' => $now, 'languages' => 0],
                ['id' => 3, 'source' => 'igdb', 'source_id' => '903', 'title' => 'Hades', 'refreshed_at' => $now, 'created_at' => $now, 'languages' => 0],
            ],
        ]);
        $this->app()->getContainer()->instance(GameBases::class, new FakeGameBases([
            '901' => ['id' => '900', 'title' => 'Pragmata'],
            '902' => ['id' => '900', 'title' => 'Pragmata'],
        ]));

        $this->assertSame('Linked 2 editions and DLC to their game.', $this->runCommand(['command' => 'curio:link-editions']));

        $rows = $this->database()->table('curio_works')->orderBy('id')->get(['base_id', 'base_title'])->map(fn ($row) => [$row->base_id, $row->base_title])->all();
        // A game that is its own is marked asked (''), so the next run leaves it alone.
        $this->assertSame([['900', 'Pragmata'], ['900', 'Pragmata'], ['', null]], $rows);
    }

    #[Test]
    public function the_shelf_import_says_so_when_shelf_is_not_installed(): void
    {
        $this->assertSame('Shelf is not installed here.', $this->runCommand(['command' => 'curio:import-shelf']));
    }

    #[Test]
    public function matching_with_nothing_waiting_matches_nothing(): void
    {
        $this->assertSame('Matched 0 of 0.', $this->runCommand(['command' => 'curio:match-works']));
    }
}

/** IGDB's answer, without IGDB. */
class FakeGameBases extends GameBases
{
    /** @param array<string, array{id: string, title: string}> $answer */
    public function __construct(private array $answer)
    {
    }

    public function for(string $sourceKey, array $ids): ?array
    {
        return array_intersect_key($this->answer, array_flip($ids));
    }
}
