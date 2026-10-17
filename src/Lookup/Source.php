<?php

namespace Ernestdefoe\Curio\Lookup;

/**
 * Somewhere titles come from. Answers are normalised here, so nothing else
 * sees a service's own format.
 *
 * A result: {id, title, creator, year, cover} where cover is a remote image URL
 * from one of RemoteImage::HOSTS. Details add `wikidata` ([property, value]
 * linking the title to Wikidata) and optionally `fields` (type field values).
 */
interface Source
{
    /** The key stored with a work, e.g. "openlibrary". */
    public function key(): string;

    /** @return list<array{id: string, title: string, creator: ?string, year: ?int, cover: ?string}> */
    public function search(string $query): array;

    /**
     * @return array{title: string, creator: ?string, year: ?int, cover: ?string, wikidata: ?array{0: string, 1: string}, description?: ?string, language?: ?string}
     * @throws LookupException when the source has no such title
     */
    public function details(string $id): array;
}
