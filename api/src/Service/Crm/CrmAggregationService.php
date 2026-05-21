<?php

declare(strict_types=1);

namespace MyInvoice\Service\Crm;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * CRM dashboard aggregation queries.
 *
 * Čte z `crm_monthly_summary` (pre-aggregated přes sp_recompute_crm_monthly_summary).
 * Plus live queries pro top klienti/vendoři (z invoices/purchase_invoices direct).
 *
 * Period filters:
 *   - 'current_month' / 'last_month' / 'ytd' (year-to-date) / 'last_12m'
 *
 * Multi-currency: vrací breakdown per currency. UI nabídne CurrencyPicker.
 */
final class CrmAggregationService
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Volá sp_recompute_crm_monthly_summary pro daný tenant.
     * Manuálně z admin UI nebo z cron jobu.
     */
    public function recompute(int $supplierId): void
    {
        $this->db->pdo()->prepare('CALL sp_recompute_crm_monthly_summary(?)')->execute([$supplierId]);
    }

    /**
     * Overview KPI: aktuální měsíc + minulý měsíc + YTD (per currency).
     *
     * @return array{
     *   current_month: array<int, array<string,mixed>>,
     *   last_month: array<int, array<string,mixed>>,
     *   ytd: array<int, array<string,mixed>>,
     *   currencies: list<string>
     * }
     */
    public function overview(int $supplierId): array
    {
        $now = new \DateTimeImmutable();
        $currentMonth = $now->format('Y-m');
        $lastMonth = $now->modify('-1 month')->format('Y-m');
        $yearStart = $now->format('Y-01');

        return [
            'current_month' => $this->loadMonth($supplierId, $currentMonth),
            'last_month'    => $this->loadMonth($supplierId, $lastMonth),
            'ytd'           => $this->loadRange($supplierId, $yearStart, $currentMonth),
            'currencies'    => $this->listCurrencies($supplierId),
        ];
    }

    /**
     * Měsíční breakdown za posledních N měsíců (default 12). Per currency.
     *
     * @return list<array{period:string, currency:string, revenue:float, costs:float,
     *                    profit:float, invoice_count:int, purchase_count:int}>
     */
    public function monthlyHistory(int $supplierId, int $monthsBack = 12, ?string $currency = null): array
    {
        $start = (new \DateTimeImmutable())->modify('-' . $monthsBack . ' months')->format('Y-m');
        $params = [$supplierId, $start];
        $where = ' AND period_ym >= ?';
        if ($currency !== null) {
            $where .= ' AND currency = ?';
            $params[] = $currency;
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT period_ym, currency, revenue, revenue_net, costs, costs_net,
                    invoice_count, purchase_count, vat_output, vat_input
               FROM crm_monthly_summary
              WHERE supplier_id = ?{$where}
           ORDER BY period_ym ASC, currency ASC"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(function ($r) {
            $rev = (float) $r['revenue'];
            $costs = (float) $r['costs'];
            return [
                'period'          => (string) $r['period_ym'],
                'currency'        => (string) $r['currency'],
                'revenue'         => $rev,
                'revenue_net'     => (float) $r['revenue_net'],
                'costs'           => $costs,
                'costs_net'       => (float) $r['costs_net'],
                'profit'          => $rev - $costs,
                'invoice_count'   => (int) $r['invoice_count'],
                'purchase_count'  => (int) $r['purchase_count'],
                'vat_output'      => (float) $r['vat_output'],
                'vat_input'       => (float) $r['vat_input'],
            ];
        }, $rows);
    }

    /**
     * Top klienti by revenue za posledních N měsíců.
     *
     * @return list<array{client_id:int, company_name:string, revenue:float,
     *                    invoice_count:int, currency:string, percent_share:float}>
     */
    public function topClients(int $supplierId, int $monthsBack = 12, int $limit = 10, ?string $currency = null): array
    {
        $start = (new \DateTimeImmutable())->modify('-' . $monthsBack . ' months')->format('Y-m-01');
        $params = [$supplierId, $start];
        $where = '';
        if ($currency !== null) {
            $where .= ' AND cur.code = ?';
            $params[] = $currency;
        }
        $sql = "
            SELECT i.client_id, c.company_name, cur.code AS currency,
                   SUM(COALESCE(i.total_with_vat, 0)) AS revenue,
                   COUNT(*) AS invoice_count,
                   SUM(SUM(COALESCE(i.total_with_vat, 0))) OVER (PARTITION BY cur.code) AS total_per_currency
              FROM invoices i
              JOIN clients c ON c.id = i.client_id
              JOIN currencies cur ON cur.id = i.currency_id
             WHERE i.supplier_id = ?
               AND i.issue_date >= ?
               AND i.status NOT IN ('draft', 'cancelled')
               AND i.invoice_type != 'proforma'{$where}
          GROUP BY i.client_id, c.company_name, cur.code
          ORDER BY revenue DESC
             LIMIT " . (int) $limit;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(function ($r) {
            $rev = (float) $r['revenue'];
            $total = (float) $r['total_per_currency'];
            return [
                'client_id'     => (int) $r['client_id'],
                'company_name'  => (string) $r['company_name'],
                'revenue'       => $rev,
                'invoice_count' => (int) $r['invoice_count'],
                'currency'      => (string) $r['currency'],
                'percent_share' => $total > 0 ? round(($rev / $total) * 100, 2) : 0.0,
            ];
        }, $rows);
    }

    /**
     * Top vendors by costs.
     */
    public function topVendors(int $supplierId, int $monthsBack = 12, int $limit = 10, ?string $currency = null): array
    {
        $start = (new \DateTimeImmutable())->modify('-' . $monthsBack . ' months')->format('Y-m-01');
        $params = [$supplierId, $start];
        $where = '';
        if ($currency !== null) {
            $where .= ' AND cur.code = ?';
            $params[] = $currency;
        }
        $sql = "
            SELECT pi.vendor_id, c.company_name, cur.code AS currency,
                   SUM(COALESCE(pi.total_with_vat, 0)) AS costs,
                   COUNT(*) AS purchase_count,
                   SUM(SUM(COALESCE(pi.total_with_vat, 0))) OVER (PARTITION BY cur.code) AS total_per_currency
              FROM purchase_invoices pi
              JOIN clients c ON c.id = pi.vendor_id
         LEFT JOIN currencies cur ON cur.id = pi.currency_id
             WHERE pi.supplier_id = ?
               AND pi.issue_date >= ?
               AND pi.status NOT IN ('draft', 'cancelled'){$where}
          GROUP BY pi.vendor_id, c.company_name, cur.code
          ORDER BY costs DESC
             LIMIT " . (int) $limit;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(function ($r) {
            $costs = (float) $r['costs'];
            $total = (float) $r['total_per_currency'];
            return [
                'vendor_id'      => (int) $r['vendor_id'],
                'company_name'   => (string) $r['company_name'],
                'costs'          => $costs,
                'purchase_count' => (int) $r['purchase_count'],
                'currency'       => (string) ($r['currency'] ?? 'CZK'),
                'percent_share'  => $total > 0 ? round(($costs / $total) * 100, 2) : 0.0,
            ];
        }, $rows);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadMonth(int $supplierId, string $periodYm): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT period_ym, currency, revenue, revenue_net, costs, costs_net,
                    invoice_count, purchase_count, vat_output, vat_input
               FROM crm_monthly_summary
              WHERE supplier_id = ? AND period_ym = ?
           ORDER BY currency ASC"
        );
        $stmt->execute([$supplierId, $periodYm]);
        return array_map(fn ($r) => $this->castSummary($r), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadRange(int $supplierId, string $fromYm, string $toYm): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT currency,
                    SUM(revenue) AS revenue, SUM(revenue_net) AS revenue_net,
                    SUM(costs)   AS costs,   SUM(costs_net)   AS costs_net,
                    SUM(invoice_count) AS invoice_count,
                    SUM(purchase_count) AS purchase_count,
                    SUM(vat_output) AS vat_output, SUM(vat_input) AS vat_input
               FROM crm_monthly_summary
              WHERE supplier_id = ? AND period_ym >= ? AND period_ym <= ?
           GROUP BY currency
           ORDER BY currency ASC"
        );
        $stmt->execute([$supplierId, $fromYm, $toYm]);
        return array_map(fn ($r) => $this->castSummary($r), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    private function castSummary(array $r): array
    {
        return [
            'period'         => $r['period_ym'] ?? null,
            'currency'       => (string) $r['currency'],
            'revenue'        => (float) $r['revenue'],
            'revenue_net'    => (float) $r['revenue_net'],
            'costs'          => (float) $r['costs'],
            'costs_net'      => (float) $r['costs_net'],
            'profit'         => (float) $r['revenue'] - (float) $r['costs'],
            'invoice_count'  => (int) $r['invoice_count'],
            'purchase_count' => (int) $r['purchase_count'],
            'vat_output'     => (float) $r['vat_output'],
            'vat_input'      => (float) $r['vat_input'],
        ];
    }

    /**
     * @return list<string>
     */
    private function listCurrencies(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT currency FROM crm_monthly_summary WHERE supplier_id = ? ORDER BY currency'
        );
        $stmt->execute([$supplierId]);
        return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'currency');
    }
}
