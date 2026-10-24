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
            'a European listing' => ['Mario Kart 8 Deluxe - Nintendo Switch Video Game - European Version', ['platform' => 'nintendo-switch', 'region' => 'pal'], 'Mario Kart 8 Deluxe'],
            'the platform in the game name stays' => ['Nintendo Switch Sports - Nintendo Switch [Physical]', ['platform' => 'nintendo-switch'], 'Nintendo Switch Sports'],
            'Icecat: edition, languages and platform' => ['Elden Ring: Shadow of the Erdtree Standard Multilingue PlayStation 5', ['platform' => 'playstation-5'], 'Elden Ring: Shadow of the Erdtree'],
            'a French edition' => ['Hogwarts Legacy : L\'Héritage de Poudlard - Édition Deluxe (PS5)', ['platform' => 'playstation-5'], 'Hogwarts Legacy : L\'Héritage de Poudlard'],
            'an English edition and short platform' => ['Final Fantasy VII Rebirth Deluxe Edition PS5', ['platform' => 'playstation-5'], 'Final Fantasy VII Rebirth'],
            'a language list is not a region' => ['Nintendo The Legend of Zelda: Tears of the Kingdom Standard Danish, German, English, Japanese, Korean Nintendo Switch', ['platform' => 'nintendo-switch'], 'Nintendo The Legend of Zelda: Tears of the Kingdom Standard Danish, German, English, Japanese, Korean'],
            'short words inside a title stay' => ['Among Us', [], 'Among Us'],
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
