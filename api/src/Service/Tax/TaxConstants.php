<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax;

/**
 * Roční daňové konstanty (CZ). V produkční v1 půjdou z tabulky `tax_constants`
 * (migrace 0073) — tahle třída je referenční zdroj pro seed a fallback, aby šel
 * engine spustit i bez DB (testy, CLI demo).
 *
 * ⚠️ Před ostrým seedem ověřit hodnoty označené TODO z Finanční správy
 * (min. vyměřovací základy a hranici 23 % pro 2026).
 */
final class TaxConstants
{
    /**
     * @return array<string, mixed> konstanty pro daný rok
     */
    public static function forYear(int $year): array
    {
        return self::TABLE[$year] ?? self::TABLE[2026];
    }

    public static function availableYears(): array
    {
        return array_keys(self::TABLE);
    }

    private const TABLE = [
        2025 => [
            'year' => 2025,
            // Paušální daň — roční částka dle pásma (12× měsíční záloha)
            'pausal_annual' => ['band1' => 104592, 'band2' => 200940, 'band3' => 325668],
            // Stropy pásem dle příjmu × výdajového paušálu (§7a ZDP).
            // Klíč = sazba výdajového paušálu; hodnota = strop pro [band1, band2, band3].
            // Pozn.: SummaryAction::FLAT_TAX_BANDS drží zjednodušenou (činnost-neutrální)
            // variantu týchž stropů; sjednotit dashboard na tento zdroj je follow-up (v2).
            'band_ceilings' => [
                30 => ['band1' => 1000000, 'band2' => 1500000, 'band3' => 2000000],
                40 => ['band1' => 1000000, 'band2' => 1500000, 'band3' => 2000000],
                60 => ['band1' => 1500000, 'band2' => 2000000, 'band3' => 2000000],
                80 => ['band1' => 2000000, 'band2' => 2000000, 'band3' => 2000000],
            ],
            // Slevy a zvýhodnění
            'credit_taxpayer' => 30840,
            'credit_spouse'   => 24840,
            'child_credits'   => [15204, 22320, 27840], // 1., 2., 3.+ dítě (3.+ se opakuje)
            // Daň z příjmu
            'tax_rate_low'        => 0.15,
            'tax_rate_high'       => 0.23,
            'tax_high_threshold'  => 1676052, // 36× průměrné mzdy 2025
            // Pojistné
            'social_rate'         => 0.292,
            'health_rate'         => 0.135,
            'assessment_base_pct' => 0.55,
            'social_min_base_main'      => 186852, // TODO ověřit (min. roční vyměřovací základ, hlavní činnost)
            'social_min_base_secondary' => 99666,  // TODO ověřit (vedlejší činnost / rozhodná částka)
            'health_min_base'           => 251040, // TODO ověřit
            // Výdajové paušály — strop uplatnitelných výdajů dle sazby
            'expense_caps' => [30 => 600000, 40 => 800000, 60 => 1200000, 80 => 1600000],
            // Odpočty — stropy
            'mortgage_cap' => 150000,
            'pension_cap'  => 48000,
            // DPH
            'vat_limit_low'  => 2000000,
            'vat_limit_high' => 2536500,
        ],
        2026 => [
            'year' => 2026,
            'pausal_annual' => ['band1' => 119808, 'band2' => 200940, 'band3' => 325668],
            'band_ceilings' => [
                30 => ['band1' => 1000000, 'band2' => 1500000, 'band3' => 2000000],
                40 => ['band1' => 1000000, 'band2' => 1500000, 'band3' => 2000000],
                60 => ['band1' => 1500000, 'band2' => 2000000, 'band3' => 2000000],
                80 => ['band1' => 2000000, 'band2' => 2000000, 'band3' => 2000000],
            ],
            'credit_taxpayer' => 30840,
            'credit_spouse'   => 24840,
            'child_credits'   => [15204, 22320, 27840], // TODO ověřit 2026
            'tax_rate_low'        => 0.15,
            'tax_rate_high'       => 0.23,
            'tax_high_threshold'  => 1762812, // TODO ověřit (36× průměrné mzdy 2026)
            'social_rate'         => 0.292,
            'health_rate'         => 0.135,
            'assessment_base_pct' => 0.55,
            'social_min_base_main'      => 199000, // TODO ověřit (roste)
            'social_min_base_secondary' => 106000, // TODO ověřit
            'health_min_base'           => 267000, // TODO ověřit
            'expense_caps' => [30 => 600000, 40 => 800000, 60 => 1200000, 80 => 1600000],
            'mortgage_cap' => 150000,
            'pension_cap'  => 48000,
            'vat_limit_low'  => 2000000,
            'vat_limit_high' => 2536500,
        ],
    ];
}
