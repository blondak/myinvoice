<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Mapper VAT klasifikací — code → dphdp3_line, kh_section, sazba.
 *
 * Pro každého tenanta načte:
 *   - Globální seed kódy (supplier_id IS NULL)
 *   - Per-tenant override (supplier_id = $supplierId) — pokud existuje, vyhraje
 */
final class VatClassificationMapper
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Vrátí mapu code → {label, direction, dphdp3_line, kh_section, vat_rate, is_reverse_charge}
     *
     * @return array<string, array{label:string, direction:string, dphdp3_line:?string,
     *                              kh_section:?string, vat_rate:?float, is_reverse_charge:bool}>
     */
    public function loadMap(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT code, label, direction, dphdp3_line, kh_section, vat_rate, is_reverse_charge
               FROM vat_classifications
              WHERE (supplier_id IS NULL OR supplier_id = ?)
                AND archived = 0
           ORDER BY supplier_id IS NULL ASC, display_order ASC'
        );
        $stmt->execute([$supplierId]);
        $map = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $map[(string) $r['code']] = [
                'label'             => (string) $r['label'],
                'direction'         => (string) $r['direction'],
                'dphdp3_line'       => $r['dphdp3_line'] !== null ? (string) $r['dphdp3_line'] : null,
                'kh_section'        => $r['kh_section'] !== null ? (string) $r['kh_section'] : null,
                'vat_rate'          => $r['vat_rate'] !== null ? (float) $r['vat_rate'] : null,
                'is_reverse_charge' => (bool) $r['is_reverse_charge'],
            ];
        }
        return $map;
    }

    /**
     * Aggregace pro DPH přiznání DPHDP3 — vrátí summary per řádek výkazu.
     *
     * Z invoices + purchase_invoices + their items podle období (rok+měsíc/čtvrtletí).
     * Pro každou fakturu/řádek najde vat_classification_code (item-level override → invoice-level fallback).
     *
     * @return array<string, array{base:float, vat:float, count:int, label:string}>
     *         Klíč = dphdp3_line (řádek výkazu), value = sumy + meta.
     */
    public function aggregateForDphPriznani(int $supplierId, int $year, int $month): array
    {
        $map = $this->loadMap($supplierId);
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = (new \DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');

        $byLine = [];
        // Vystavené (revenue side)
        $rows = $this->db->pdo()->prepare(
            "SELECT
                  COALESCE(ii.vat_classification_code, i.vat_classification_code) AS code,
                  SUM(COALESCE(ii.total_without_vat, 0)) AS base_total,
                  SUM(COALESCE(ii.total_vat, 0))         AS vat_total,
                  COUNT(DISTINCT i.id) AS inv_count
             FROM invoices i
             JOIN invoice_items ii ON ii.invoice_id = i.id
            WHERE i.supplier_id = ?
              AND i.status NOT IN ('draft', 'cancelled')
              AND i.invoice_type != 'proforma'
              AND COALESCE(i.tax_date, i.issue_date) BETWEEN ? AND ?
         GROUP BY code"
        );
        $rows->execute([$supplierId, $start, $end]);
        foreach ($rows->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $code = $r['code'];
            if (!$code) continue;
            $clsf = $map[$code] ?? null;
            if ($clsf === null || $clsf['dphdp3_line'] === null) continue;
            $line = $clsf['dphdp3_line'];
            if (!isset($byLine[$line])) {
                $byLine[$line] = ['base' => 0.0, 'vat' => 0.0, 'count' => 0, 'label' => $clsf['label']];
            }
            $byLine[$line]['base'] += (float) $r['base_total'];
            $byLine[$line]['vat']  += (float) $r['vat_total'];
            $byLine[$line]['count'] += (int) $r['inv_count'];
        }

        // Přijaté (cost side — nárok na odpočet)
        $rows = $this->db->pdo()->prepare(
            "SELECT
                  COALESCE(pii.vat_classification_code, pi.vat_classification_code) AS code,
                  SUM(COALESCE(pii.total_without_vat, 0)) AS base_total,
                  SUM(COALESCE(pii.total_vat, 0))         AS vat_total,
                  COUNT(DISTINCT pi.id) AS inv_count
             FROM purchase_invoices pi
             JOIN purchase_invoice_items pii ON pii.purchase_invoice_id = pi.id
            WHERE pi.supplier_id = ?
              AND pi.status NOT IN ('draft', 'cancelled')
              AND COALESCE(pi.tax_date, pi.issue_date) BETWEEN ? AND ?
         GROUP BY code"
        );
        $rows->execute([$supplierId, $start, $end]);
        foreach ($rows->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $code = $r['code'];
            if (!$code) continue;
            $clsf = $map[$code] ?? null;
            if ($clsf === null || $clsf['dphdp3_line'] === null) continue;
            $line = $clsf['dphdp3_line'];
            if (!isset($byLine[$line])) {
                $byLine[$line] = ['base' => 0.0, 'vat' => 0.0, 'count' => 0, 'label' => $clsf['label']];
            }
            $byLine[$line]['base'] += (float) $r['base_total'];
            $byLine[$line]['vat']  += (float) $r['vat_total'];
            $byLine[$line]['count'] += (int) $r['inv_count'];
        }

        return $byLine;
    }
}
