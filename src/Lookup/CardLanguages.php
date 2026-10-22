<?php

namespace Ernestdefoe\Curio\Lookup;

/**
 * A card source with printings in several languages: the look-up's "Card
 * language" menu picks which one is searched.
 */
interface CardLanguages
{
    /**
     * This source searching the cards printed in one language ("ja"), or, for
     * null or a language it does not have, the reader's own as before.
     */
    public function inLanguage(?string $lang): static;
}
