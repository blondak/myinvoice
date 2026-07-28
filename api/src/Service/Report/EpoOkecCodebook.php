<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use DateTimeImmutable;

/**
 * Číselník ČINNOSTI (zkratka OKEC) z Daňového portálu MFČR — proti němu EPO
 * runtime validuje atribut `c_okec` (propustná chyba 30 „Hlavní ekonomická
 * činnost by měla odpovídat nějaké hodnotě z číselníku").
 *
 * Zdroj dat: https://adisspr.mfcr.cz/mepo/pub/api/ciselniky/soubor/okec
 * (rozhraní číselníků: https://adisspr.mfcr.cz/pmd/dokumentace/ciselniky).
 * Snapshot v `api/resources/ciselniky/okec.txt` — pipe-delimited TXT přesně
 * tak, jak ho portál publikuje (`c_nace|d_pocpl|d_ukopl|naz_nace|`).
 * Aktualizace = stáhnout nový soubor (gzip) a nahradit; formát se nemění.
 *
 * Klíčová fakta o číselníku (stav snapshotu 2026-07, aktualizace 9. 7. 2026):
 *   - Kódy jsou koncepčně 6místné: 4místná třída NACE × 100, u některých
 *     odvětví s národní podtřídou na 5.–6. pozici (621010, 649230, …).
 *   - Sloupec je číselného typu, takže kódy sekcí 01–09 jsou v číselníku
 *     uloženy BEZ vodicí nuly („14800" = 01.48.00). Porovnáváme proto
 *     numericky a emitujeme kanonickou podobu z číselníku.
 *   - Záznamy mají platnost od/do: k 1. 1. 2026 se číselník překlopil na
 *     NACE rev. 2.1 — všechny starší kódy mají d_ukopl 2025-12-31
 *     (např. 620200 „poradenství v IT" nahradilo 622000). Kód správné
 *     šířky tedy může být přesto odmítnut, protože EXPIROVAL — přesně
 *     to je pozadí issue #157.
 */
final class EpoOkecCodebook
{
    private const RESOURCE = __DIR__ . '/../../../resources/ciselniky/okec.txt';

    /** Výsledek: kód nalezen a platný k referenčnímu datu. */
    public const STATUS_ACTIVE = 'active';
    /** Kód v číselníku existuje, ale jeho platnost skončila (viz valid_to). */
    public const STATUS_EXPIRED = 'expired';
    /** Kód v číselníku není (snapshot může být i zastaralý — nikdy neblokovat). */
    public const STATUS_UNKNOWN = 'unknown';

    /** @var array<int, list<array{code: string, from: string, to: string, name: string}>>|null */
    private static ?array $index = null;

    /**
     * Normalizuje vstup („73.11", „7311", „62020", „731100", „01480", …) na
     * kanonický kód číselníku a řekne, jak na tom je s platností.
     *
     * Kandidáti se zkoušejí numericky v pořadí: (1) vstup tak, jak je
     * (pokrývá kanonické hodnoty vč. bez-nulových „14800"), (2) doplnění
     * nulami zprava na 6 míst (transformace ČSÚ zápisu: třída 7311 → 731100,
     * podtřída 62020 → 620200). Preferuje se kandidát platný k $at; když
     * žádný platný není, vrací se existující expirovaný (s valid_to); když
     * kód v číselníku není vůbec, vrací se doplněná podoba se STATUS_UNKNOWN.
     *
     * @return array{code: string, status: string, name: ?string, valid_to: ?string}|null
     *         null = po odstranění nečíslic méně než 4 číslice (oddíl z ARES)
     *         → atribut c_okec se má vynechat / uložení odmítnout.
     */
    public static function normalize(string $raw, ?DateTimeImmutable $at = null): ?array
    {
        $digits = preg_replace('/\D/', '', $raw) ?? '';
        if (strlen($digits) < 4) {
            return null;
        }
        if (strlen($digits) > 6) {
            $digits = substr($digits, 0, 6);
        }
        $at ??= new DateTimeImmutable('today');
        $atStr = $at->format('Y-m-d');

        $padded = str_pad($digits, 6, '0', STR_PAD_RIGHT);
        $candidates = array_unique([(int) $digits, (int) $padded]);

        $expired = null;
        foreach ($candidates as $value) {
            foreach (self::index()[$value] ?? [] as $entry) {
                $fromOk = $entry['from'] === '' || $entry['from'] <= $atStr;
                $toOk = $entry['to'] === '' || $entry['to'] >= $atStr;
                if ($fromOk && $toOk) {
                    return [
                        'code' => $entry['code'],
                        'status' => self::STATUS_ACTIVE,
                        'name' => $entry['name'],
                        'valid_to' => $entry['to'] !== '' ? $entry['to'] : null,
                    ];
                }
                // Nejpozději končící expirovaný záznam jako fallback pro hlášku.
                if ($expired === null || $entry['to'] > $expired['valid_to']) {
                    $expired = [
                        'code' => $entry['code'],
                        'status' => self::STATUS_EXPIRED,
                        'name' => $entry['name'],
                        'valid_to' => $entry['to'],
                    ];
                }
            }
        }
        if ($expired !== null) {
            return $expired;
        }
        return ['code' => $padded, 'status' => self::STATUS_UNKNOWN, 'name' => null, 'valid_to' => null];
    }

    /** @return array<int, list<array{code: string, from: string, to: string, name: string}>> */
    private static function index(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }
        $index = [];
        $fh = @fopen(self::RESOURCE, 'rb');
        if ($fh !== false) {
            fgets($fh); // hlavička c_nace|d_pocpl|d_ukopl|naz_nace|
            while (($line = fgets($fh)) !== false) {
                $cols = explode('|', rtrim($line, "\r\n"));
                if (count($cols) < 4 || $cols[0] === '') {
                    continue;
                }
                $index[(int) $cols[0]][] = [
                    'code' => $cols[0],
                    'from' => trim($cols[1]),
                    'to' => trim($cols[2]),
                    'name' => trim($cols[3]),
                ];
            }
            fclose($fh);
        }
        return self::$index = $index;
    }
}
