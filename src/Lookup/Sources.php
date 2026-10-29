<?php

namespace Ernestdefoe\Curio\Lookup;

use Ernestdefoe\Curio\Lookup\Sources\CardGames;
use Ernestdefoe\Curio\Lookup\Sources\Deezer;
use Ernestdefoe\Curio\Lookup\Sources\Igdb;
use Ernestdefoe\Curio\Lookup\Sources\Marquee;
use Ernestdefoe\Curio\Lookup\Sources\MusicBrainz;
use Ernestdefoe\Curio\Lookup\Sources\OpenLibrary;
use Ernestdefoe\Curio\Lookup\Sources\Scryfall;
use Ernestdefoe\Curio\Lookup\Sources\TcgDex;
use Ernestdefoe\Curio\Lookup\Sources\YgoProDeck;
use Ernestdefoe\Curio\Type;
use Flarum\Extension\ExtensionManager;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;

/**
 * Which source answers for a type, and which one a stored work came from.
 * Books and music are built in (no keys). Games come from IGDB when the forum
 * has a Twitch app set up in Curio's settings. Films, TV and (without IGDB)
 * games come through Marquee's own sources when Marquee is enabled and set up.
 * Trading cards (free, no keys): Pokémon from TCGdex, Magic from Scryfall,
 * Yu-Gi-Oh! from YGOPRODeck, or all three; the admin picks per type.
 */
class Sources
{
    /** A type's source → Marquee's kind. */
    private const MARQUEE_KINDS = ['movies' => 'movie', 'tv' => 'tv', 'games' => 'game'];

    public function __construct(
        private Container $container,
        private ExtensionManager $extensions,
        private OpenLibrary $openLibrary,
        private Deezer $deezer,
        private MusicBrainz $musicBrainz,
        private Igdb $igdb,
        private TcgDex $tcgdex,
        private Scryfall $scryfall,
        private YgoProDeck $ygoprodeck
    ) {
    }

    /** The source searched for a type, or null when it has none here. */
    public function forType(Type $type): ?Source
    {
        return match ($type->source) {
            'books' => $this->openLibrary,
            'music' => $this->deezer,
            'games' => $this->igdb->configured() ? $this->igdb : $this->marquee('game'),
            'movies', 'tv' => $this->marquee(self::MARQUEE_KINDS[$type->source]),
            'pokemon' => $this->tcgdex,
            'magic' => $this->scryfall,
            'yugioh' => $this->ygoprodeck,
            'cards' => new CardGames($this->tcgdex, $this->scryfall, $this->ygoprodeck),
            default => null,
        };
    }

    /** The source a stored work's key names, or null. */
    public function byKey(string $key): ?Source
    {
        return match (true) {
            $key === 'openlibrary' => $this->openLibrary,
            $key === 'deezer' => $this->deezer,
            $key === 'musicbrainz' => $this->musicBrainz,
            $key === 'igdb' => $this->igdb->configured() ? $this->igdb : null,
            $key === 'tcgdex' => $this->tcgdex,
            $key === 'scryfall' => $this->scryfall,
            $key === 'ygoprodeck' => $this->ygoprodeck,
            str_starts_with($key, 'marquee-') && in_array(substr($key, 8), self::MARQUEE_KINDS, true) => $this->marquee(substr($key, 8)),
            default => null,
        };
    }

    public function searchable(Type $type): bool
    {
        return $this->forType($type) !== null;
    }

    private function marquee(string $kind): ?Source
    {
        $registry = 'Ernestdefoe\Marquee\Source\Sources';

        if (! class_exists($registry) || ! $this->extensions->isEnabled('ernestdefoe-marquee')) {
            return null;
        }

        $source = $this->container->make($registry)->get($kind);

        if (! $source) {
            return null;
        }

        // A title someone linked a discussion to is stored by Marquee already: "Add to
        // collection" on its card reads that copy instead of asking TMDB or IGDB again.
        $details = function (string $id) use ($source, $kind) {
            $stored = $this->container->make(ConnectionInterface::class)
                ->table('marquee_media')->where('kind', $kind)->where('external_id', $id)->value('data');
            $data = is_string($stored) ? json_decode($stored, true) : null;

            return is_array($data) && isset($data['title']) ? $data : $source->details($id);
        };

        return new Marquee($kind, fn (string $q) => $source->search($q), $details);
    }
}
