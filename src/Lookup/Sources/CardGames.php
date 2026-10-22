<?php

namespace Ernestdefoe\Curio\Lookup\Sources;

use Ernestdefoe\Curio\Lookup\LookupException;
use Ernestdefoe\Curio\Lookup\Source;

/**
 * Pokémon, Magic and Yu-Gi-Oh! at once, for a type that holds all three:
 * each answers (from its cache when it can) and the results take turns, each
 * naming the source it came from.
 */
class CardGames implements Source
{
    /** @var array<string, Source> */
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
        $lists = [];
        $failed = 0;

        foreach ($this->games as $game => $source) {
            try {
                $lists[] = array_map(fn ($r) => ['source' => $source->key(), 'creator' => implode(' · ', array_filter([$game, $r['creator'] ?? null]))] + $r, $source->search($query));
            } catch (LookupException $e) {
                $failed++;
            }
        }

        if ($failed === count($this->games)) {
            throw new LookupException('No card source answered', true);
        }

        $merged = [];
        for ($i = 0; count($merged) < 9 && $lists && $i < 8; $i++) {
            foreach ($lists as $list) {
                if (isset($list[$i])) {
                    $merged[] = $list[$i];
                }
            }
        }

        return array_slice($merged, 0, 9);
    }

    public function details(string $id): array
    {
        // Every result names its own source; a stored work never says "cards".
        throw new LookupException('Card results are resolved by their own source');
    }
}
