<?php

declare(strict_types=1);

/**
 * Detekce + report přijatých faktur, kde AI zaměnila vendor↔customer.
 *
 * Symptom: purchase_invoices.vendor_id ukazuje na klienta, jehož IČ je
 * shodné s tenant supplier.ic (tenant je v pozici dodavatele, což u purchase
 * importu nedává smysl — tenant je vždy odběratel/plátce).
 *
 * Fix v AiPdfExtractor (commit ec06e4c+) řeší nové importy. Tento skript
 * jen najde a vypíše už zaimportované swap kandidáty pro manuální nápravu:
 *
 *   1. Smazat purchase invoice (DELETE z UI nebo SQL)
 *   2. Znovu importovat původní PDF (z PurchaseInvoicePdf historie nebo z disku)
 *   3. Nový import už správně rozezná vendor a customer
 *
 * Použití:
 *   php api/bin/backfill-vendor-swap.php           # report
 *   php api/bin/backfill-vendor-swap.php --apply   # status='cancelled' na nalezené
 */

require __DIR__ . '/../vendor/autoload.php';

$dryRun = !in_array('--apply', $argv, true);

$app = \MyInvoice\Bootstrap::buildApp();
$container = $app->getContainer();
$pdo = $container->get(\MyInvoice\Infrastructure\Database\Connection::class)->pdo();

// Najdi swap kandidáty: vendor_id ukazuje na klienta s IČ shodným s tenant.ic
$stmt = $pdo->query(
    "SELECT pi.id, pi.supplier_id, pi.vendor_invoice_number, pi.issue_date,
            pi.total_with_vat, pi.status, pi.document_kind,
            s.ic AS tenant_ic, s.company_name AS tenant_name,
            c.ic AS vendor_ic, c.company_name AS vendor_name
       FROM purchase_invoices pi
       JOIN clients c ON c.id = pi.vendor_id
       JOIN supplier s ON s.id = pi.supplier_id
      WHERE c.ic IS NOT NULL
        AND s.ic IS NOT NULL
        AND TRIM(c.ic) = TRIM(s.ic)
        AND pi.status != 'cancelled'
      ORDER BY pi.supplier_id, pi.issue_date, pi.id"
);
$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

if (empty($rows)) {
    echo "Žádné přijaté faktury s vendor IČ = tenant IČ — žádný swap.\n";
    exit(0);
}

$mode = $dryRun ? '[DRY-RUN] ' : '';
echo "{$mode}Nalezeno " . count($rows) . " swap kandidátů (vendor IČ = tenant IČ):\n\n";

foreach ($rows as $r) {
    printf(
        "  #%-6d tenant=%-2d  %-15s  %s  %s  total=%.2f  status=%-10s  kind=%s\n",
        $r['id'], $r['supplier_id'],
        substr((string) $r['vendor_invoice_number'], 0, 15),
        $r['issue_date'],
        substr((string) $r['vendor_name'], 0, 20),
        (float) $r['total_with_vat'],
        $r['status'],
        $r['document_kind']
    );
    printf("      tenant=%s (%s)  vs  vendor=%s (%s)\n",
        $r['tenant_name'], $r['tenant_ic'],
        $r['vendor_name'], $r['vendor_ic']);

    if (!$dryRun) {
        try {
            $pdo->prepare("UPDATE purchase_invoices SET status='cancelled', cancelled_at=NOW() WHERE id=?")
                ->execute([(int) $r['id']]);
            echo "      → status='cancelled'\n";
        } catch (\Throwable $e) {
            echo "      ✗ DB: " . $e->getMessage() . "\n";
        }
    }
}

if ($dryRun) {
    echo "\nKandidátů: " . count($rows) . ".  Doporučený postup:\n";
    echo "  1. Otevři každou fakturu v UI a stáhni PDF (z karty přijaté faktury)\n";
    echo "  2. Smaž ji (nebo nech 'cancelled' přes --apply)\n";
    echo "  3. Znovu importuj PDF — nová logika (AiPdfExtractor) detekuje swap a opraví ho\n";
    echo "\nSpusť s --apply pro hromadné označení status='cancelled'.\n";
} else {
    echo "\nHotovo. Označeno cancelled: " . count($rows) . "\n";
    echo "Re-importuj PDF v UI pro správné vytvoření faktur.\n";
}
