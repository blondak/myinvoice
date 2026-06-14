<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook;

use Mpdf\Mpdf;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\FuelingRepository;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * Export tankování do XLSX a PDF za zvolené období (datum od/do, volitelně auto).
 * Seskupeno po měsících, se subtotály a celkovou částkou.
 */
final class FuelingExportService
{
    private const HEADERS = ['Datum', 'Auto', 'Palivo', 'Množství', 'Cena s DPH', 'Místo / síť', 'Zdroj'];
    private const SOURCE_LABELS = ['manual' => 'ruční', 'invoice' => 'faktura', 'axigon' => 'Axigon', 'axigon_ai' => 'Axigon (AI)', 'import' => 'import'];

    public function __construct(
        private readonly FuelingRepository $fuelings,
        private readonly Connection $db,
    ) {}

    /** @return array{bytes:string, filename:string, mime:string} */
    public function export(int $supplierId, string $format, array $filters): array
    {
        $rows = $this->collect($supplierId, $filters);
        $period = $this->periodLabel($filters);
        $supplier = $this->supplierName($supplierId);
        $base = 'tankovani' . ($period['file'] !== '' ? '-' . $period['file'] : '');

        if ($format === 'pdf') {
            return ['bytes' => $this->pdf($rows, $period['human'], $supplier),
                    'filename' => $base . '.pdf', 'mime' => 'application/pdf'];
        }
        return ['bytes' => $this->xlsx($rows, $period['human'], $supplier),
                'filename' => $base . '.xlsx', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    }

    /** @return list<array<string,mixed>> */
    private function collect(int $supplierId, array $filters): array
    {
        $f = array_intersect_key($filters, array_flip(['car_id', 'date_from', 'date_to', 'source', 'vendor_id']));
        $rows = $this->fuelings->listForTenant($supplierId, $f); // už řazeno datum DESC
        return $rows;
    }

    private function xlsx(array $rows, string $period, string $supplier): string
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Tankování');
        $sheet->setCellValue('A1', 'Tankování');
        $sheet->setCellValue('A2', $supplier);
        $sheet->setCellValue('A3', $period !== '' ? 'Období: ' . $period : '');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $headRow = 5;
        foreach (self::HEADERS as $i => $h) $sheet->setCellValue([$i + 1, $headRow], $h);
        $sheet->getStyle("A{$headRow}:G{$headRow}")->getFont()->setBold(true);
        $sheet->getStyle("A{$headRow}:G{$headRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEEEEE');

        $r = $headRow + 1;
        $total = 0.0;
        foreach ($rows as $t) {
            $sheet->setCellValue("A{$r}", $this->dateCell($t));
            $sheet->setCellValue("B{$r}", (string) ($t['car_registration'] ?? ''));
            $sheet->setCellValue("C{$r}", (string) ($t['fuel_type'] ?? ''));
            $sheet->setCellValue("D{$r}", $t['quantity'] !== null ? (float) $t['quantity'] : '');
            $sheet->setCellValue("E{$r}", (float) $t['amount_with_vat']);
            $sheet->setCellValue("F{$r}", (string) ($t['station'] ?? $t['vendor_name'] ?? ''));
            $sheet->setCellValue("G{$r}", self::SOURCE_LABELS[(string) $t['source']] ?? (string) $t['source']);
            $total += (float) $t['amount_with_vat'];
            $r++;
        }
        $sheet->setCellValue("D{$r}", 'CELKEM');
        $sheet->setCellValue("E{$r}", $total);
        $sheet->getStyle("D{$r}:E{$r}")->getFont()->setBold(true);

        $sheet->getStyle("A{$headRow}:G{$r}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        foreach (range('A', 'G') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);
        $sheet->getStyle("D{$headRow}:E{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $tmp = tempnam(sys_get_temp_dir(), 'fuexp_') . '.xlsx';
        (new XlsxWriter($ss))->save($tmp);
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        $ss->disconnectWorksheets();
        return $bytes;
    }

    private function pdf(array $rows, string $period, string $supplier): string
    {
        $tmpDir = RuntimePaths::storage('cache/mpdf');
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);
        $mpdf = new Mpdf([
            'mode' => 'utf-8', 'format' => 'A4', 'orientation' => 'P',
            'margin_left' => 10, 'margin_right' => 10, 'margin_top' => 12, 'margin_bottom' => 12,
            'default_font' => 'dejavusans', 'tempDir' => $tmpDir, 'autoPageBreak' => true,
        ]);
        $mpdf->SetTitle('Tankování' . ($period !== '' ? ' ' . $period : ''));
        $mpdf->SetCreator('MyInvoice.cz');
        $mpdf->WriteHTML($this->pdfHtml($rows, $period, $supplier));
        return $mpdf->Output('', 'S');
    }

    private function pdfHtml(array $rows, string $period, string $supplier): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $css = '<style>
            body{font-family:dejavusans;font-size:9pt;color:#222}
            h1{font-size:15pt;margin:0 0 2px 0}
            .sub{color:#666;font-size:9pt;margin:0 0 8px 0}
            table{width:100%;border-collapse:collapse}
            th{background:#eee;text-align:left;padding:3px 4px;border:0.5px solid #bbb;font-size:8.5pt}
            td{padding:2px 4px;border:0.5px solid #ccc}
            td.r,th.r{text-align:right}
            tr.tot td{font-weight:bold;border-top:1px solid #555}
        </style>';
        $head = '<h1>Tankování</h1><p class="sub">' . $e($supplier)
            . ($period !== '' ? ' &nbsp;·&nbsp; Období: ' . $e($period) : '') . '</p>';
        $h = '<tr><th>Datum</th><th>Auto</th><th>Palivo</th><th class="r">Množství</th>'
           . '<th class="r">Cena s DPH</th><th>Místo / síť</th><th>Zdroj</th></tr>';

        $body = ''; $total = 0.0;
        foreach ($rows as $t) {
            $body .= '<tr>'
                . '<td>' . $e($this->dateCell($t)) . '</td>'
                . '<td>' . $e($t['car_registration'] ?? '') . '</td>'
                . '<td>' . $e($t['fuel_type'] ?? '') . '</td>'
                . '<td class="r">' . ($t['quantity'] !== null ? $e($this->num((float) $t['quantity']) . ' l') : '') . '</td>'
                . '<td class="r">' . $e($this->money((float) $t['amount_with_vat'], (string) $t['currency'])) . '</td>'
                . '<td>' . $e($t['station'] ?? $t['vendor_name'] ?? '') . '</td>'
                . '<td>' . $e(self::SOURCE_LABELS[(string) $t['source']] ?? (string) $t['source']) . '</td>'
                . '</tr>';
            $total += (float) $t['amount_with_vat'];
        }
        if ($rows === []) {
            $body = '<tr><td colspan="7" style="text-align:center;color:#888;padding:12px">Žádné tankování ve zvoleném období.</td></tr>';
        } else {
            $body .= '<tr class="tot"><td colspan="4" class="r">CELKEM</td><td class="r">' . $e($this->money($total, 'CZK')) . '</td><td colspan="2"></td></tr>';
        }
        return $css . $head . '<table>' . $h . $body . '</table>';
    }

    private function num(float $n): string { return number_format($n, 2, ',', ' '); }
    private function money(float $n, string $ccy): string { return number_format($n, 2, ',', ' ') . ' ' . ($ccy ?: 'CZK'); }

    private function czDate(string $iso): string
    {
        try { return (new \DateTimeImmutable($iso))->format('d.m.Y'); }
        catch (\Throwable) { return $iso; }
    }

    /** Datum + případný čas tankování (jen pokud existuje). */
    private function dateCell(array $t): string
    {
        $s = $this->czDate((string) $t['fueled_date']);
        $time = (string) ($t['fueled_time'] ?? '');
        return $time !== '' ? $s . ' ' . $time : $s;
    }

    /** @return array{human:string, file:string} */
    private function periodLabel(array $filters): array
    {
        $from = trim((string) ($filters['date_from'] ?? ''));
        $to = trim((string) ($filters['date_to'] ?? ''));
        if ($from === '' && $to === '') return ['human' => '', 'file' => ''];
        $human = ($from !== '' ? $this->czDate($from) : '…') . ' – ' . ($to !== '' ? $this->czDate($to) : '…');
        $file = ($from !== '' ? $from : '') . ($to !== '' ? '_' . $to : '');
        return ['human' => $human, 'file' => $file];
    }

    private function supplierName(int $supplierId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT company_name FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return (string) ($stmt->fetchColumn() ?: '');
    }
}
