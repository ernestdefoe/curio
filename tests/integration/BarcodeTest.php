<?php

namespace Ernestdefoe\Curio\Tests\integration;

use Ernestdefoe\Curio\Lookup\Barcode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class BarcodeTest extends TestCase
{
    public static function codes(): array
    {
        return [
            'UPC-A' => ['045496590420', '0045496590420'],
            'EAN-13' => ['4006381333931', '4006381333931'],
            'ISBN-13' => ['9780441172719', '9780441172719'],
            'ISBN-10 to 13' => ['0441172717', '9780441172719'],
            'ISBN-10 with X' => ['080442957X', '9780804429573'],
            'spaces and dashes' => ['978-0-441-17271-9', '9780441172719'],
            'UPC-E expanded' => ['04252614', '0042100005264'],
            'EAN-8' => ['96385074', '0000096385074'],
            'bad check digit' => ['045496590421', null],
            'too short' => ['12345', null],
            'letters' => ['abc123', null],
        ];
    }

    #[Test]
    #[DataProvider('codes')]
    public function normalises_barcodes(string $input, ?string $expected): void
    {
        $this->assertSame($expected, Barcode::normalise($input));
    }

    #[Test]
    public function knows_isbns_and_upcs(): void
    {
        $this->assertTrue(Barcode::isIsbn('9780441172719'));
        $this->assertFalse(Barcode::isIsbn('0045496590420'));
        $this->assertSame('045496590420', Barcode::upc('0045496590420'));
        $this->assertSame('4006381333931', Barcode::upc('4006381333931'));
    }
}
