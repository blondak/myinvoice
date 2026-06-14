<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Logbook;

use MyInvoice\Service\Logbook\Fuel\FuelKeywords;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FuelKeywordsTest extends TestCase
{
    #[DataProvider('fuelCases')]
    public function testIsFuel(string $desc, bool $expected): void
    {
        self::assertSame($expected, FuelKeywords::isFuel($desc));
    }

    public static function fuelCases(): array
    {
        return [
            ['Prémiová nafta', true],
            ['Natural 95', true],
            ['Diesel plus', true],
            ['NAFTA MOTOROVÁ', true],
            ['AdBlue', true],
            ['Benzín BA95', true],
            ['Verva Diesel', true],
            // Nepalivové služby — non-fuel vyhrává i kdyby obsahovaly palivové slovo.
            ['Mytí vozu', false],
            ['Plošná cena', false],
            ['Dálniční známka', false],
            ['Parkovné', false],
            ['', false],
        ];
    }

    public function testNonFuelService(): void
    {
        self::assertTrue(FuelKeywords::isNonFuelService('Mytí vozu'));
        self::assertTrue(FuelKeywords::isNonFuelService('Plošná cena'));
        self::assertFalse(FuelKeywords::isNonFuelService('Prémiová nafta'));
    }

    public function testNormalizeStripsDiacritics(): void
    {
        self::assertSame('premiova nafta', FuelKeywords::normalize('  Prémiová   Nafta '));
    }
}
