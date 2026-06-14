<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro logbook_fuel_scans (marker „faktura už vytěžena") + dotazy na faktury
 * od dodavatelů-benzínek (is_fuel_station=1). Per tenant (supplier_id).
 */
final class FuelScanRepository
{
    public function __construct(private readonly Connection $db) {}

    public function isScanned(int $supplierId, int $purchaseInvoiceId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM logbook_fuel_scans WHERE supplier_id = ? AND purchase_invoice_id = ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $purchaseInvoiceId]);
        return $stmt->fetchColumn() !== false;
    }

    public function recordScan(int $supplierId, int $purchaseInvoiceId, string $parser, int $count, string $status): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO logbook_fuel_scans (supplier_id, purchase_invoice_id, parser, transactions_count, status)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE parser = VALUES(parser), transactions_count = VALUES(transactions_count),
                                     status = VALUES(status), scanned_at = CURRENT_TIMESTAMP'
        )->execute([$supplierId, $purchaseInvoiceId, $parser, $count, $status]);
    }

    /**
     * Faktury od benzínek pro přehled „Načíst z faktur".
     *
     * @param array{only_unscanned?:bool, year?:int} $filters
     * @return list<array<string,mixed>>
     */
    public function listFuelStationInvoices(int $supplierId, array $filters = []): array
    {
        $where = ['pi.supplier_id = ?', 'cl.is_fuel_station = 1', "pi.document_kind <> 'advance'"];
        $params = [$supplierId];
        if (!empty($filters['year'])) { $where[] = 'YEAR(pi.issue_date) = ?'; $params[] = (int) $filters['year']; }
        $sql = 'SELECT pi.id, pi.vendor_id, pi.issue_date, pi.vendor_invoice_number, pi.document_kind,
                       pi.total_with_vat, pi.pdf_path,
                       cl.company_name AS vendor_name, cl.ic AS vendor_ic,
                       cur.code AS currency,
                       (SELECT COUNT(*) FROM fuelings f WHERE f.source_purchase_invoice_id = pi.id) AS fuelings_count,
                       (SELECT COUNT(*) FROM logbook_fuel_scans s
                         WHERE s.purchase_invoice_id = pi.id AND s.supplier_id = pi.supplier_id) AS scanned
                  FROM purchase_invoices pi
                  JOIN clients cl   ON cl.id  = pi.vendor_id
                  JOIN currencies cur ON cur.id = pi.currency_id
                 WHERE ' . implode(' AND ', $where);
        if (!empty($filters['only_unscanned'])) {
            $sql .= ' AND NOT EXISTS (SELECT 1 FROM logbook_fuel_scans s
                                       WHERE s.purchase_invoice_id = pi.id AND s.supplier_id = pi.supplier_id)';
        }
        $sql .= ' ORDER BY pi.issue_date DESC, pi.id DESC';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map(function (array $r): array {
            return [
                'id'                    => (int) $r['id'],
                'vendor_id'             => (int) $r['vendor_id'],
                'vendor_name'           => (string) $r['vendor_name'],
                'vendor_ic'             => $r['vendor_ic'] !== null ? (string) $r['vendor_ic'] : null,
                'issue_date'            => $r['issue_date'] !== null ? (string) $r['issue_date'] : null,
                'vendor_invoice_number' => $r['vendor_invoice_number'] !== null ? (string) $r['vendor_invoice_number'] : null,
                'document_kind'         => $r['document_kind'] !== null ? (string) $r['document_kind'] : null,
                'total_with_vat'        => (float) $r['total_with_vat'],
                'currency'              => (string) $r['currency'],
                'has_pdf'               => ((string) ($r['pdf_path'] ?? '')) !== '',
                'fuelings_count'        => (int) $r['fuelings_count'],
                'scanned'               => (int) $r['scanned'] > 0,
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * ID faktur od benzínek, které ještě nebyly vytěženy — pro dávkový backfill.
     *
     * @return list<int>
     */
    public function unscannedInvoiceIds(int $supplierId, int $limit): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT pi.id
               FROM purchase_invoices pi
               JOIN clients cl ON cl.id = pi.vendor_id
              WHERE pi.supplier_id = ? AND cl.is_fuel_station = 1 AND pi.document_kind <> 'advance'
                AND NOT EXISTS (SELECT 1 FROM logbook_fuel_scans s
                                 WHERE s.purchase_invoice_id = pi.id AND s.supplier_id = pi.supplier_id)
              ORDER BY pi.issue_date ASC, pi.id ASC
              LIMIT ?"
        );
        $stmt->bindValue(1, $supplierId, PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
