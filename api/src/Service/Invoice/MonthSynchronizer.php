<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use DateTimeImmutable;

/**
 * Synchronizuje měsíc/rok v popisku položky s konkrétním datem (typicky DUZP
 * vygenerované faktury). Na rozdíl od {@see MonthIncrementer} nepřičítá +N
 * měsíců — nahrazuje pattern M/YYYY přímo měsícem/rokem z předaného data.
 *
 * Použití v {@see RecurringInvoiceGenerator}: popis šablony obsahuje období
 * (např. "Hosting - 05/2026"). Při každém vygenerování se M/YYYY synchronizuje
 * k tax_date (DUZP) faktury, takže:
 *   - 1. cyklus (issue/tax_date 5/2026) → "05/2026"
 *   - 2. cyklus (issue/tax_date 6/2026) → "06/2026"
 *   - šablonová hodnota se nikdy nemění; sync je idempotentní
 *
 * Podporované formáty (stejné jako {@see MonthIncrementer}, jen místo inkrementu
 * substituce — rok musí být přesně 4 číslice, jinak je dvojice ambiguózní):
 *   M/YYYY, MM/YYYY        "3/2026"   → "5/2026"    (pro target 5/2026)
 *   YYYY-MM, YYYY-M        "2026-03"  → "2026-05"
 *   YYYY/MM                "2026/03"  → "2026/05"
 *   MM.YYYY, M.YYYY        "12.2025"  → "5.2026"
 *   MM-YYYY, M-YYYY        "12-2025"  → "5-2026"
 *
 * Zachovává původní separátor i zero-padding měsíce.
 * Plná data ("2026-05-15", "20.5.2026") jsou chráněna lookaroundy a nemění se.
 */
final class MonthSynchronizer
{
    public static function syncTo(string $text, DateTimeImmutable $target): string
    {
        $targetYear  = (int) $target->format('Y');
        $targetMonth = (int) $target->format('n');

        return preg_replace_callback(
            '/(?<![\d.\/\-])(\d{1,4})([.\/\-])(\d{1,4})(?![\d.\/\-])/',
            function ($m) use ($targetYear, $targetMonth) {
                [$full, $left, $sep, $right] = $m;
                $leftLen  = strlen($left);
                $rightLen = strlen($right);

                // Identifikuj, která strana je rok (přesně 4 číslice).
                // Padding měsíce: ISO formát "YYYY-MM" vždy paduje (konvence).
                // Month-first formáty padují jen když uživatel sám napsal leading zero.
                if ($leftLen === 4 && $rightLen >= 1 && $rightLen <= 2) {
                    $origMonth   = (int) $right;
                    $yearFirst   = true;
                    $monthPadded = true;
                } elseif ($rightLen === 4 && $leftLen >= 1 && $leftLen <= 2) {
                    $origMonth   = (int) $left;
                    $yearFirst   = false;
                    $monthPadded = $leftLen === 2 && $left[0] === '0';
                } else {
                    return $full;
                }

                if ($origMonth < 1 || $origMonth > 12) {
                    return $full; // neplatný měsíc — necháme být
                }

                $monthStr = $monthPadded ? sprintf('%02d', $targetMonth) : (string) $targetMonth;
                return $yearFirst
                    ? "{$targetYear}{$sep}{$monthStr}"
                    : "{$monthStr}{$sep}{$targetYear}";
            },
            $text,
        ) ?? $text;
    }
}
