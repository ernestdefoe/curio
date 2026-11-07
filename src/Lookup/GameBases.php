<?php

namespace Ernestdefoe\Curio\Lookup;

use Ernestdefoe\Curio\Lookup\Sources\Igdb;
use Flarum\Extension\ExtensionManager;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * The game an edition (Deluxe, GOTY) or a DLC belongs to, from IGDB's own
 * links: version_parent for an edition, parent_game for a DLC. Expansions,
 * standalone expansions, remakes and bundles keep their own discussion
 * (Wlork, Discuss d/40107 #124). Asked once, when a game is first stored,
 * with Curio's IGDB keys or Marquee's.
 */
class GameBases
{
    private const DLC = 1;

    private const CLIENT = 'Ernestdefoe\Marquee\Source\IgdbClient';

    public function __construct(private Igdb $igdb, private ExtensionManager $extensions, private Container $container)
    {
    }

    /**
     * Each id's game, for the ids that are an edition or DLC (at most 500);
     * null when IGDB could not be asked (no keys, down), to try again later.
     *
     * @param list<string> $ids
     * @return array<string, array{id: string, title: string}>|null
     */
    public function for(string $sourceKey, array $ids): ?array
    {
        $ids = array_slice(array_values(array_filter($ids, fn ($id) => ctype_digit($id) && strlen($id) <= 12)), 0, 500);
        if (! $ids) {
            return [];
        }

        $body = 'fields game_type,version_parent.name,parent_game.name; where id = ('.implode(',', $ids).'); limit 500;';

        try {
            $client = match (true) {
                $sourceKey === 'igdb' => $this->igdb,
                $sourceKey === 'marquee-game' && class_exists(self::CLIENT) && $this->extensions->isEnabled('ernestdefoe-marquee') => $this->container->make(self::CLIENT),
                default => null,
            };
            $games = $client?->configured() ? $client->query('games', $body) : null;
        } catch (Throwable $e) {
            return null;
        }

        return $games === null ? null : self::bases($games);
    }

    /**
     * Public so it is tested without credentials.
     *
     * @param list<mixed> $games
     * @return array<string, array{id: string, title: string}>
     */
    public static function bases(array $games): array
    {
        $bases = [];
        foreach ($games as $g) {
            $base = is_array($g) ? ($g['version_parent'] ?? (($g['game_type'] ?? null) === self::DLC ? $g['parent_game'] ?? null : null)) : null;
            if (isset($g['id'], $base['id'], $base['name']) && is_int($base['id']) && is_string($base['name'])) {
                $bases[(string) $g['id']] = ['id' => (string) $base['id'], 'title' => mb_substr($base['name'], 0, 255)];
            }
        }

        return $bases;
    }
}
