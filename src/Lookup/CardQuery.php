<?php

namespace Ernestdefoe\Curio\Lookup;

/**
 * What a card search asks for: "Goomy 195/264", "Goomy fst 195",
 * "Lightning Bolt 2x2 117", "LOB-EN001". The trailing number (with an
 * optional set total) is the card's number; words just before it may name
 * the set. Which words are the set is not known until a search says so, so
 * attempts() lists the readings to try, most literal first.
 */
final class CardQuery
{
    /** A printed card number: "195", "TG05", "SWSH020", "117a", "195/264". */
    private const NUMBER = '#^\#?([a-z]{0,5}\d{1,4}[a-z]?)(?:/([a-z]{0,5}\d{1,4}))?$#i';

    /** A Yu-Gi-Oh! printing code: "LOB-EN001", "SDY-006", "RA01-FR054". */
    public const CODE = '/^[a-z0-9]{2,5}-[a-z]{0,2}\d{1,4}$/i';

    /** @param list<string> $words */
    private function __construct(public readonly array $words, public readonly ?string $number, public readonly ?string $total, public readonly ?string $code)
    {
    }

    public static function parse(string $query): self
    {
        $words = preg_split('/\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $code = null;
        foreach ($words as $i => $word) {
            if (preg_match(self::CODE, $word)) {
                $code = strtoupper($word);
                unset($words[$i]);
            }
        }
        $words = array_values($words);
        $number = $total = null;

        if (count($words) > 1 && preg_match(self::NUMBER, end($words), $m)) {
            array_pop($words);
            $number = $m[1];
            $total = $m[2] ?? null;
        }

        return new self($words, $number, $total, $code);
    }

    /**
     * Readings to try in turn, each [name, set words or null, number or null]:
     * all words as the name; then the last one or two as the set; then the
     * same without the number, in case it was part of the name.
     *
     * @return list<array{0: string, 1: ?string, 2: ?string}>
     */
    public function attempts(): array
    {
        $readings = [];
        $numbers = $this->number === null ? [null] : [$this->number, null];

        foreach ($numbers as $number) {
            $words = $number === null && $this->number !== null ? [...$this->words, $this->number.($this->total ? "/$this->total" : '')] : $this->words;
            $readings[] = [implode(' ', $words), null, $number];
            for ($k = 1; $k <= min(2, count($words) - 1); $k++) {
                $readings[] = [implode(' ', array_slice($words, 0, -$k)), implode(' ', array_slice($words, -$k)), $number];
            }
        }

        return array_values(array_filter($readings, fn ($r) => $r[0] !== ''));
    }

    /** "036" is "36"; "TG05" is "tg5". */
    public static function sameNumber(string $printed, string $asked): bool
    {
        $plain = fn (string $n) => preg_replace('/(?<![0-9])0+(?=\d)/', '', strtolower(explode('/', $n)[0]));

        return $plain($printed) === $plain($asked);
    }

    /** Lower case, letters and digits only: "Fusion Strike" → "fusionstrike". */
    public static function plain(string $text): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($text));
    }
}
