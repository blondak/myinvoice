<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Report;

use DateTimeImmutable;
use MyInvoice\Service\Report\EpoOkecCodebook;
use PHPUnit\Framework\TestCase;

/**
 * Kanonizace a platnost CZ-NACE kódů proti snapshotu číselníku ČINNOSTI
 * (OKEC) z Daňového portálu — api/resources/ciselniky/okec.txt.
 *
 * Číselník se k 1. 1. 2026 překlopil na NACE rev. 2.1: starší kódy mají
 * d_ukopl 2025-12-31 (např. 620200 „poradenství v IT", nástupce 622000).
 * Kódy sekcí 01–09 jsou v číselníku uloženy numericky bez vodicí nuly
 * („14800" = 01.48.00). Testy používají pevné referenční datum, aby
 * nezávisely na dnešku; hodnoty odpovídají snapshotu z 07/2026.
 */
final class EpoOkecCodebookTest extends TestCase
{
    private static function at(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date);
    }

    public function testActiveClassCodeIsPaddedToCodebookValue(): void
    {
        $r = EpoOkecCodebook::normalize('73.11', self::at('2026-07-28'));
        self::assertNotNull($r);
        self::assertSame('731100', $r['code']);
        self::assertSame(EpoOkecCodebook::STATUS_ACTIVE, $r['status']);
        self::assertNotSame('', (string) $r['name']);
    }

    public function testCanonicalCodebookValueStaysUntouched(): void
    {
        $r = EpoOkecCodebook::normalize('731100', self::at('2026-07-28'));
        self::assertNotNull($r);
        self::assertSame('731100', $r['code']);
        self::assertSame(EpoOkecCodebook::STATUS_ACTIVE, $r['status']);
    }

    public function testLeadingZeroSectionResolvesToNumericCanonicalForm(): void
    {
        // 01.48 (chov ostatních zvířat) je v číselníku uložen jako „14800".
        foreach (['0148', '01480', '014800', '14800'] as $input) {
            $r = EpoOkecCodebook::normalize($input, self::at('2026-07-28'));
            self::assertNotNull($r, "vstup $input");
            self::assertSame('14800', $r['code'], "vstup $input");
            self::assertSame(EpoOkecCodebook::STATUS_ACTIVE, $r['status'], "vstup $input");
        }
    }

    public function testNace20CodeIsExpiredAfterSwitchToNace21(): void
    {
        // Přesně případ issue #157: 62020/620200 bylo do 31. 12. 2025 platné,
        // NACE rev. 2.1 ho nahradila kódem 622000. Odmítnutí v EPO způsobila
        // expirace, ne šířka kódu — „62020" v číselníku nikdy nebylo.
        $r = EpoOkecCodebook::normalize('62020', self::at('2026-07-28'));
        self::assertNotNull($r);
        self::assertSame('620200', $r['code']);
        self::assertSame(EpoOkecCodebook::STATUS_EXPIRED, $r['status']);
        self::assertSame('2025-12-31', $r['valid_to']);

        // Totéž datum před přechodem: kód byl platný.
        $r2 = EpoOkecCodebook::normalize('62020', self::at('2025-06-30'));
        self::assertNotNull($r2);
        self::assertSame('620200', $r2['code']);
        self::assertSame(EpoOkecCodebook::STATUS_ACTIVE, $r2['status']);
    }

    public function testSuccessorCodeIsActive(): void
    {
        $r = EpoOkecCodebook::normalize('622000', self::at('2026-07-28'));
        self::assertNotNull($r);
        self::assertSame(EpoOkecCodebook::STATUS_ACTIVE, $r['status']);
    }

    public function testUnknownCodeKeepsPaddedFormWithUnknownStatus(): void
    {
        $r = EpoOkecCodebook::normalize('9999', self::at('2026-07-28'));
        self::assertNotNull($r);
        self::assertSame('999900', $r['code']);
        self::assertSame(EpoOkecCodebook::STATUS_UNKNOWN, $r['status']);
        self::assertNull($r['name']);
    }

    public function testSectionOnlyInputReturnsNull(): void
    {
        self::assertNull(EpoOkecCodebook::normalize('74', self::at('2026-07-28')));
        self::assertNull(EpoOkecCodebook::normalize('7.4', self::at('2026-07-28')));
        self::assertNull(EpoOkecCodebook::normalize('', self::at('2026-07-28')));
    }
}
