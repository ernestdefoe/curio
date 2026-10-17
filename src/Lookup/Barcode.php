<?php

namespace Ernestdefoe\Curio\Lookup;

/** Barcode arithmetic: EAN-13, UPC-A, EAN-8, UPC-E and ISBN-10, by check digit. */
final class Barcode
{
    /**
     * The barcode as 13 digits (EAN-13; UPC-A gets a leading zero), or null
     * when it is not a valid barcode.
     */
    public static function normalise(string $input): ?string
    {
        $code = strtoupper(preg_replace('/[\s-]+/', '', $input) ?? '');

        if (preg_match('/^\d{9}[\dX]$/', $code)) {
            return self::isbn10to13($code);
        }

        if (! preg_match('/^\d+$/', $code)) {
            return null;
        }

        return match (strlen($code)) {
            13 => self::valid($code) ? $code : null,
            12 => self::valid('0'.$code) ? '0'.$code : null,
            8 => self::valid($code) ? '00000'.$code : (in_array($code[0], ['0', '1'], true) ? self::upcE($code) : null),
            default => null,
        };
    }

    public static function isIsbn(string $ean): bool
    {
        return str_starts_with($ean, '978') || str_starts_with($ean, '979');
    }

    /** The 12-digit UPC-A form for a 13-digit code starting with 0, else the code. */
    public static function upc(string $ean): string
    {
        return $ean[0] === '0' ? substr($ean, 1) : $ean;
    }

    private static function valid(string $digits): bool
    {
        $sum = 0;
        $length = strlen($digits);

        for ($i = 0; $i < $length - 1; $i++) {
            // Weights 1,3,1,3… for 13 digits; counted from the right for any length.
            $sum += (int) $digits[$i] * ((($length - 1 - $i) % 2 === 1) ? 3 : 1);
        }

        return (10 - $sum % 10) % 10 === (int) $digits[$length - 1];
    }

    private static function isbn10to13(string $isbn): ?string
    {
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += ($isbn[$i] === 'X' ? 10 : (int) $isbn[$i]) * (10 - $i);
        }

        if ($sum % 11 !== 0) {
            return null;
        }

        $body = '978'.substr($isbn, 0, 9);
        $check = 0;
        for ($i = 0; $i < 12; $i++) {
            $check += (int) $body[$i] * ($i % 2 ? 3 : 1);
        }

        return $body.((10 - $check % 10) % 10);
    }

    private static function upcE(string $e): ?string
    {
        [$n, $d1, $d2, $d3, $d4, $d5, $d6, $c] = str_split($e);

        $a = match (true) {
            in_array($d6, ['0', '1', '2'], true) => "$n$d1$d2{$d6}0000$d3$d4$d5$c",
            $d6 === '3' => "$n$d1$d2{$d3}00000$d4$d5$c",
            $d6 === '4' => "$n$d1$d2$d3{$d4}00000$d5$c",
            default => "$n$d1$d2$d3$d4{$d5}0000$d6$c",
        };

        return self::valid('0'.$a) ? '0'.$a : null;
    }
}
