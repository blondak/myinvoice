<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook\Fuel;

/**
 * Klíčová slova pro rozpoznání pohonných hmot na položkách faktur a v detailních
 * výpisech benzínek. Match je diakritiku-insensitive a case-insensitive.
 */
final class FuelKeywords
{
    /** Pohonné hmoty / palivo (řádek se stane tankováním). */
    private const FUEL = [
        'nafta', 'diesel', 'benzin', 'natural', 'super', 'premium', 'premiova',
        'adblue', 'ad blue', 'lpg', 'cng', 'phm', 'pohonne hmoty', 'pohonnych hmot', 'palivo',
        'efecta', 'verva', 'miles',
    ];

    /** Nepalivové služby na benzínce (řádek se NEstane tankováním). */
    private const NON_FUEL = [
        'myti', 'mytí', 'plosna cena', 'plošná cena', 'poplatek', 'dalnicni', 'dálniční',
        'mytne', 'mýtné', 'znamka', 'známka', 'parkovani', 'parkování', 'obcerstveni', 'občerstvení',
    ];

    /** SQL fragment (LOWER(col) REGEXP …) pro hrubý filtr palivových položek na fakturách. */
    public const SQL_REGEXP = 'benzin|nafta|diesel|natural|adblue|phm|palivo|pohonn|premiov|verva|efecta';

    public static function isFuel(string $text): bool
    {
        $n = self::normalize($text);
        if ($n === '') return false;
        // Nejdřív vyluč jednoznačné nepalivové služby.
        foreach (self::NON_FUEL as $kw) {
            if (str_contains($n, self::normalize($kw))) {
                // „premium myti" apod. je služba — non-fuel vyhrává.
                return false;
            }
        }
        foreach (self::FUEL as $kw) {
            if (str_contains($n, self::normalize($kw))) return true;
        }
        return false;
    }

    public static function isNonFuelService(string $text): bool
    {
        $n = self::normalize($text);
        foreach (self::NON_FUEL as $kw) {
            if (str_contains($n, self::normalize($kw))) return true;
        }
        return false;
    }

    /** Odstraní diakritiku, lowercase, sjednotí whitespace. */
    public static function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $map = [
            'á'=>'a','č'=>'c','ď'=>'d','é'=>'e','ě'=>'e','í'=>'i','ň'=>'n','ó'=>'o','ř'=>'r',
            'š'=>'s','ť'=>'t','ú'=>'u','ů'=>'u','ý'=>'y','ž'=>'z','ä'=>'a','ö'=>'o','ü'=>'u','ô'=>'o','ľ'=>'l','ĺ'=>'l','ŕ'=>'r',
        ];
        $s = strtr($s, $map);
        return (string) preg_replace('/\s+/', ' ', $s);
    }
}
