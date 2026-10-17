<?php

namespace Ernestdefoe\Curio\Tests\integration;

use Ernestdefoe\Curio\Lookup\BarcodeLookup;
use Ernestdefoe\Curio\Type;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Shop listings' product names → an item's details and a searchable title. */
class ProductNameTest extends TestCase
{
    private static function games(): Type
    {
        $type = new Type();
        $type->fields = [
            ['key' => 'platform', 'kind' => 'select', 'labels' => ['en' => 'Platform'], 'options' => [
                ['key' => 'nintendo-switch-2', 'labels' => ['en' => 'Nintendo Switch 2']],
                ['key' => 'nintendo-switch', 'labels' => ['en' => 'Nintendo Switch']],
                ['key' => 'playstation-5', 'labels' => ['en' => 'PlayStation 5']],
                ['key' => 'wii', 'labels' => ['en' => 'Wii']],
            ]],
            ['key' => 'region', 'kind' => 'select', 'labels' => ['en' => 'Region'], 'options' => [
                ['key' => 'pal', 'labels' => ['en' => 'PAL']],
                ['key' => 'ntsc-u', 'labels' => ['en' => 'NTSC-U']],
            ]],
            ['key' => 'cib', 'kind' => 'bool', 'labels' => ['en' => 'Complete in box']],
        ];

        return $type;
    }

    public static function names(): array
    {
        return [
            'platform after a dash' => ['The Legend of Zelda: Breath of the Wild - Nintendo Switch', ['platform' => 'nintendo-switch'], 'The Legend of Zelda: Breath of the Wild'],
            'the longer platform wins' => ['Mario Kart World - Nintendo Switch 2', ['platform' => 'nintendo-switch-2'], 'Mario Kart World'],
            'short name and region' => ['Astro Bot (PS5) [Physical] U.S. Version', ['platform' => 'playstation-5', 'region' => 'ntsc-u'], 'Astro Bot'],
            'a platform word inside the title stays' => ['Wii Sports - Nintendo Wii', ['platform' => 'wii'], 'Wii Sports'],
            'nothing recognised' => ['Some Board Game', [], 'Some Board Game'],
        ];
    }

    #[Test]
    #[DataProvider('names')]
    public function reads_product_names(string $name, array $fields, string $title): void
    {
        $found = BarcodeLookup::fieldsFrom(self::games(), $name);

        $this->assertSame($fields, $found);
        $this->assertSame($title, BarcodeLookup::cleanTitle(self::games(), $name, $found));
    }
}
