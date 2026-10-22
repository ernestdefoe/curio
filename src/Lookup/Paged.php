<?php

namespace Ernestdefoe\Curio\Lookup;

/**
 * A source with more answers than fit on one page (a name printed on
 * hundreds of cards): the look-up offers "More results".
 */
interface Paged
{
    public const PER_PAGE = 12;

    /** @return array{0: list<array<string, mixed>>, 1: bool} this page's results, and whether another page follows */
    public function page(string $query, int $page): array;
}
