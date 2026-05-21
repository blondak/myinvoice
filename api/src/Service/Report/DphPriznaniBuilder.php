<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Builder XML pro DPH přiznání (DPHDP3) — EPO portál MFČR.
 *
 * Verze EPO: 03.01 (platná 2025-2026).
 *
 * ⚠️ Vygenerované XML je POUZE POMŮCKA. Před odesláním vždy ověřit s účetní
 *    nebo daňovým poradcem. Aplikace nezaručuje regulatorní správnost.
 *
 * Schema: https://adisspr.mfcr.cz/dpr/adis/idpr_pub/dpr_info/schemas.faces
 */
final class DphPriznaniBuilder
{
    public function __construct(
        private readonly Connection $db,
        private readonly VatClassificationMapper $mapper,
    ) {}

    /**
     * Sestaví XML pro DPH přiznání za daný měsíc/rok.
     *
     * @return array{xml: string, summary: array<string, mixed>, warnings: list<string>}
     */
    public function build(int $supplierId, int $year, int $month): array
    {
        $supplier = $this->loadSupplier($supplierId);
        $warnings = [];
        if (!$supplier['is_vat_payer']) {
            $warnings[] = 'Tenant není evidovaný jako plátce DPH — výkaz nemusí být relevantní.';
        }
        if (empty($supplier['financial_office_code'])) {
            $warnings[] = 'Chybí kód finančního úřadu — XML nemusí projít validací EPO.';
        }
        if (empty($supplier['ic'])) {
            $warnings[] = 'Chybí IČO tenanta.';
        }
        if (empty($supplier['dic'])) {
            $warnings[] = 'Chybí DIČ tenanta.';
        }

        $lines = $this->mapper->aggregateForDphPriznani($supplierId, $year, $month);

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;

        // Root: <Pisemnost nazevSW="MyInvoice.cz" verzeSW="X.Y.Z">
        $pisemnost = $dom->createElement('Pisemnost');
        $pisemnost->setAttribute('nazevSW', 'MyInvoice.cz');
        $pisemnost->setAttribute('verzeSW', (string) ($this->loadAppVersion() ?? '0'));
        $dom->appendChild($pisemnost);

        // <DPHDP3 verzePis="03.01">
        $dphdp3 = $dom->createElement('DPHDP3');
        $dphdp3->setAttribute('verzePis', '03.01');
        $pisemnost->appendChild($dphdp3);

        // ── VetaD: identifikační údaje plátce ─────────────────────────
        $vetaD = $dom->createElement('VetaD');
        $vetaD->setAttribute('k_uladis', 'DPH');
        $vetaD->setAttribute('rok', (string) $year);
        $vetaD->setAttribute('mesic', (string) $month);
        if (!empty($supplier['financial_office_code'])) {
            $vetaD->setAttribute('c_ufo', (string) $supplier['financial_office_code']);
        }
        if (!empty($supplier['workplace_code'])) {
            $vetaD->setAttribute('c_pracufo', (string) $supplier['workplace_code']);
        }
        $vetaD->setAttribute('typ_platce', $supplier['taxpayer_type'] === 'po' ? 'P' : 'F');
        $vetaD->setAttribute('typ_ds', $supplier['data_box_type'] ?: 'N');
        $dphdp3->appendChild($vetaD);

        // ── VetaP: identifikace daňového subjektu ─────────────────────
        $vetaP = $dom->createElement('VetaP');
        if (!empty($supplier['dic'])) {
            $vetaP->setAttribute('dic', (string) $supplier['dic']);
        }
        if ($supplier['taxpayer_type'] === 'po') {
            $vetaP->setAttribute('typ_platce', 'P');
            $vetaP->setAttribute('nazev_pol', (string) $supplier['company_name']);
        } else {
            $vetaP->setAttribute('typ_platce', 'F');
            // FO: jméno a příjmení — zkusíme rozparsovat company_name
            $parts = explode(' ', trim((string) $supplier['company_name']), 2);
            $vetaP->setAttribute('jmeno', $parts[0] ?? '');
            $vetaP->setAttribute('prijmeni', $parts[1] ?? $parts[0] ?? '');
        }
        $vetaP->setAttribute('ulice', (string) ($supplier['street'] ?? ''));
        $vetaP->setAttribute('naz_obce', (string) ($supplier['city'] ?? ''));
        $vetaP->setAttribute('psc', (string) ($supplier['zip'] ?? ''));
        $vetaP->setAttribute('stat', (string) ($supplier['country_iso2'] ?? 'CZ'));
        if (!empty($supplier['email'])) {
            $vetaP->setAttribute('email', (string) $supplier['email']);
        }
        if (!empty($supplier['phone'])) {
            $vetaP->setAttribute('telef_cislo', (string) $supplier['phone']);
        }
        $dphdp3->appendChild($vetaP);

        // ── Veta1-3: jednotlivé řádky DPH přiznání ─────────────────────
        // Řádky 1+2: tuzemská plnění s nárokem (vystavená 21% / 12%)
        // Řádky 40+41: tuzemská plnění s odpočtem (přijatá 21% / 12%)
        // Souhrn agregovaný z VetaD/P + data lines
        $totalDanZdanitelne = 0.0;
        $totalDanOdpocitatelne = 0.0;
        foreach ($lines as $lineNum => $data) {
            $vetaLine = $dom->createElement('Veta' . $this->vetaTypeForLine((string) $lineNum));
            $vetaLine->setAttribute('c_radku', (string) $lineNum);
            $vetaLine->setAttribute('zaklad', $this->formatAmount($data['base']));
            $vetaLine->setAttribute('dan', $this->formatAmount($data['vat']));
            $vetaLine->setAttribute('popis', $data['label']);
            $dphdp3->appendChild($vetaLine);

            // Aggregate totals pro VetaR (rekapitulace)
            if ($this->isOutputLine((string) $lineNum)) {
                $totalDanZdanitelne += $data['vat'];
            } else {
                $totalDanOdpocitatelne += $data['vat'];
            }
        }

        // ── VetaR: rekapitulace ───────────────────────────────────────
        $vetaR = $dom->createElement('VetaR');
        $vetaR->setAttribute('dan_zdanit_pln', $this->formatAmount($totalDanZdanitelne));
        $vetaR->setAttribute('odpoc_dan_celkem', $this->formatAmount($totalDanOdpocitatelne));
        $vlastniDan = $totalDanZdanitelne - $totalDanOdpocitatelne;
        if ($vlastniDan >= 0) {
            $vetaR->setAttribute('dan_zocp', $this->formatAmount($vlastniDan));  // daň na výstupu
        } else {
            $vetaR->setAttribute('nadm_odp', $this->formatAmount(abs($vlastniDan)));  // nadměrný odpočet
        }
        $dphdp3->appendChild($vetaR);

        $summary = [
            'period'                  => sprintf('%04d-%02d', $year, $month),
            'lines'                   => $lines,
            'total_vat_output'        => round($totalDanZdanitelne, 2),
            'total_vat_input'         => round($totalDanOdpocitatelne, 2),
            'tax_due'                 => round($vlastniDan, 2),
            'is_excess_deduction'     => $vlastniDan < 0,
        ];

        return [
            'xml'      => $dom->saveXML() ?: '',
            'summary'  => $summary,
            'warnings' => $warnings,
        ];
    }

    /**
     * Načti tax-relevantní info o tenantovi.
     * @return array<string,mixed>
     */
    private function loadSupplier(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.id, s.company_name, s.street, s.city, s.zip,
                    COALESCE(c.iso2, 'CZ') AS country_iso2,
                    s.ic, s.dic, s.is_vat_payer,
                    s.taxpayer_type, s.vat_period, s.financial_office_code,
                    s.workplace_code, s.data_box_type, s.data_box_id, s.email, s.phone
               FROM supplier s
          LEFT JOIN countries c ON c.id = s.country_id
              WHERE s.id = ?"
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \RuntimeException("Supplier #{$supplierId} nenalezen.");
        }
        return $row;
    }

    private function loadAppVersion(): ?string
    {
        $verFile = __DIR__ . '/../../../../VERSION';
        if (is_file($verFile)) {
            return trim((string) file_get_contents($verFile)) ?: null;
        }
        return null;
    }

    /**
     * Output lines (DPH na výstupu): 1-29 dle DPHDP3.
     * Input lines (DPH na vstupu, odpočet): 40+ dle DPHDP3.
     */
    private function isOutputLine(string $line): bool
    {
        return (int) $line < 40;
    }

    /**
     * Veta typ podle čísla řádku v DPHDP3.
     * Řádky 1-26 (dodání) → Veta1
     * Řádky 30-35 (sjednocené plnění) → Veta2
     * Řádky 40-52 (odpočet) → Veta3
     */
    private function vetaTypeForLine(string $line): string
    {
        $n = (int) $line;
        if ($n >= 40) return '3';
        if ($n >= 30) return '2';
        return '1';
    }

    /**
     * Formátování částky pro EPO XML — celé číslo Kč (zaokrouhleno).
     */
    private function formatAmount(float $amount): string
    {
        return (string) (int) round($amount);
    }
}
