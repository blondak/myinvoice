<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Action\Invoice\BulkReissueAction;
use PHPUnit\Framework\TestCase;

final class IncrementMonthInStringTest extends TestCase
{
    private BulkReissueAction $bulk;

    protected function setUp(): void
    {
        // BulkReissueAction má veřejnou statickou-like metodu, ale je instance.
        // Pro test jen statické metody by stačilo, ale musíme dát konstruktor.
        // Použijeme reflexi pro vyhnutí se závislostem (DB, repo).
        $this->bulk = (new \ReflectionClass(BulkReissueAction::class))->newInstanceWithoutConstructor();
    }

    public function testSimpleIncrement(): void
    {
        self::assertSame(
            'Konzultace 4/2026',
            $this->bulk->incrementMonthInString('Konzultace 3/2026'),
        );
    }

    public function testYearRollover(): void
    {
        self::assertSame(
            'Vícepráce 1/2026',
            $this->bulk->incrementMonthInString('Vícepráce 12/2025'),
        );
    }

    public function testNoMatchKeepsText(): void
    {
        self::assertSame(
            'Nějaký popis bez data',
            $this->bulk->incrementMonthInString('Nějaký popis bez data'),
        );
    }

    public function testMultipleMatches(): void
    {
        self::assertSame(
            'Část 4/2026 a část 6/2026',
            $this->bulk->incrementMonthInString('Část 3/2026 a část 5/2026'),
        );
    }

    public function testInvalidMonthUnchanged(): void
    {
        self::assertSame(
            'Faktura 13/2026',
            $this->bulk->incrementMonthInString('Faktura 13/2026'),
        );
    }

    public function testZeroMonthUnchanged(): void
    {
        self::assertSame(
            'Faktura 0/2026',
            $this->bulk->incrementMonthInString('Faktura 0/2026'),
        );
    }

    public function testIsoYearDashMonthPadded(): void
    {
        self::assertSame(
            'Výkaz víceprací — 2026-06',
            $this->bulk->incrementMonthInString('Výkaz víceprací — 2026-05'),
        );
    }

    public function testIsoYearDashMonthRollover(): void
    {
        self::assertSame(
            'Období 2026-01',
            $this->bulk->incrementMonthInString('Období 2025-12'),
        );
    }

    public function testYearSlashMonth(): void
    {
        self::assertSame(
            'Konzultace 2026/06',
            $this->bulk->incrementMonthInString('Konzultace 2026/05'),
        );
    }

    public function testMonthDotYearGerman(): void
    {
        self::assertSame(
            'Vícepráce 1.2026',
            $this->bulk->incrementMonthInString('Vícepráce 12.2025'),
        );
    }

    public function testMonthDashYearPadded(): void
    {
        self::assertSame(
            'Práce 02-2026',
            $this->bulk->incrementMonthInString('Práce 01-2026'),
        );
    }

    public function testFullDateNotIncremented(): void
    {
        self::assertSame(
            'Smlouva 2026-05-15',
            $this->bulk->incrementMonthInString('Smlouva 2026-05-15'),
        );
    }

    public function testCzechFullDateNotIncremented(): void
    {
        self::assertSame(
            'Datum 20.5.2026',
            $this->bulk->incrementMonthInString('Datum 20.5.2026'),
        );
    }

    public function testShortYearAmbiguousNotIncremented(): void
    {
        // "5/26" je ambiguózní (může být 26. května) — bez 4-místného roku neměníme.
        self::assertSame(
            'Konzultace 5/26',
            $this->bulk->incrementMonthInString('Konzultace 5/26'),
        );
    }

    public function testMixedFormatsInOneString(): void
    {
        self::assertSame(
            'Položka 4/2026 a období 2026-09',
            $this->bulk->incrementMonthInString('Položka 3/2026 a období 2026-08'),
        );
    }
}
