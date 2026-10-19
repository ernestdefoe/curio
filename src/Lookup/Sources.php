<?php

namespace Ernestdefoe\Curio\Lookup;

use Ernestdefoe\Curio\Lookup\Sources\Deezer;
use Ernestdefoe\Curio\Lookup\Sources\Igdb;
use Ernestdefoe\Curio\Lookup\Sources\Marquee;
use Ernestdefoe\Curio\Lookup\Sources\MusicBrainz;
use Ernestdefoe\Curio\Lookup\Sources\OpenLibrary;
use Ernestdefoe\Curio\Type;
use Flarum\Extension\ExtensionManager;
use Illuminate\Contracts\Container\Container;

/**
 * Which source answers for a type, and which one a stored work came from.
 * Books and music are built in (no keys). Games come from IGDB when the forum
 * has a Twitch app set up in Curio's settings. Films, TV and (without IGDB)
 * games come through Marquee's own sources when Marquee is enabled and set up.
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
        private Igdb $igdb
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

        return $source ? new Marquee($kind, fn (string $q) => $source->search($q), fn (string $id) => $source->details($id)) : null;
    }
}
