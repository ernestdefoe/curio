<?php

namespace Ernestdefoe\Curio\Lookup\Sources;

use Ernestdefoe\Curio\Lookup\LookupException;
use Ernestdefoe\Curio\Lookup\Paged;
use Ernestdefoe\Curio\Lookup\Source;

/**
 * Pokémon, Magic and Yu-Gi-Oh! at once, for a type that holds all three:
 * each answers (from its cache when it can) and the results take turns, each
 * naming the source it came from.
 */
class CardGames implements Source, Paged
{
    /** @var array<string, Source&Paged> */
    private array $games;

    public function __construct(TcgDex $pokemon, Scryfall $magic, YgoProDeck $yugioh)
    {
        $this->games = ['Pokémon' => $pokemon, 'Magic' => $magic, 'Yu-Gi-Oh!' => $yugioh];
    }

    public function key(): string
    {
        return 'cards';
    }

    public function search(string $query): array
    {
        return $this->page($query, 1)[0];
    }

    public function page(string $query, int $page): array
    {
        $lists = [];
        $more = false;
        $failed = 0;

        foreach ($this->games as $game => $source) {
            try {
                [$results, $next] = $source->page($query, $page);
                $more = $more || $next;
                $lists[] = array_map(fn ($r) => ['source' => $source->key(), 'set' => implode(' · ', array_filter([$game, $r['set'] ?? null]))] + $r, $results);
            } catch (LookupException $e) {
                $failed++;
            }
        }

        if ($failed === count($this->games)) {
            throw new LookupException('No card source answered', true);
        }

        // Pokémon, Magic and Yu-Gi-Oh! take turns.
        $merged = [];
        for ($i = 0; $i < self::PER_PAGE; $i++) {
            foreach ($lists as $list) {
                if (isset($list[$i])) {
                    $merged[] = $list[$i];
                }
            }
        }

        return [$merged, $more];
    }

    public function details(string $id): array
    {
        // Every result names its own source; a stored work never says "cards".
        throw new LookupException('Card results are resolved by their own source');
    }
}
