<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Export\MergedInvoicePdfExporter;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

/** GET /api/invoices/export.pdf?ids=1,2,3&sign_pdf=0|1 */
final class ExportSelectedPdfAction
{
    private const MAX_INVOICES = 100;

    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly MergedInvoicePdfExporter $exporter,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant', 'readonly'], true)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }

        try {
            $ids = $this->parseIds((string) ($request->getQueryParams()['ids'] ?? ''));
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        } catch (\LengthException $e) {
            return Json::error($response, 'too_many', $e->getMessage(), 422);
        }

        foreach ($ids as $id) {
            if (!SupplierGuard::owns($request, $this->invoices->find($id))) {
                return Json::error($response, 'not_found', 'Jedna z vybraných faktur nebyla nalezena.', 404);
            }
        }

        $supplierId = SupplierGuard::currentId($request);
        $userId = isset($user['id']) ? (int) $user['id'] : null;
        $sign = filter_var(
            $request->getQueryParams()['sign_pdf'] ?? false,
            FILTER_VALIDATE_BOOLEAN,
        );

        ini_set('display_errors', '0');
        ini_set('html_errors', '0');
        ob_start();
        try {
            $result = $this->exporter->export($ids, ['id' => $supplierId], $userId, $sign);
        } catch (\DomainException $e) {
            ob_end_clean();
            return Json::error($response, 'signature_unavailable', $e->getMessage(), 422);
        } catch (\Throwable $e) {
            ob_end_clean();
            return Json::error($response, 'export_failed', $e->getMessage(), 500);
        }
        ob_end_clean();

        $path = $result['path'];
        register_shutdown_function(static function () use ($path): void {
            if (is_file($path)) @unlink($path);
        });

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoices.merged_pdf_exported', $userId, null, null, [
            'invoice_ids' => $ids,
            'count' => count($ids),
            'signed_pdf' => $result['signed'],
        ], $ip, $request->getHeaderLine('User-Agent'));

        $filename = 'myinvoice-vybrane-faktury-' . date('Y-m-d') . '.pdf';
        $stream = new Stream(fopen($path, 'rb'));
        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string) filesize($path))
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($stream);
    }

    /** @return list<int> */
    private function parseIds(string $raw): array
    {
        if ($raw === '') {
            throw new \InvalidArgumentException('Není vybrána žádná faktura.');
        }

        $ids = [];
        foreach (explode(',', $raw) as $part) {
            if (preg_match('/^[1-9][0-9]*$/', $part) !== 1) {
                throw new \InvalidArgumentException('Parametr ids musí obsahovat čísla oddělená čárkou.');
            }
            $ids[(int) $part] = (int) $part;
        }
        $ids = array_values($ids);
        if (count($ids) > self::MAX_INVOICES) {
            throw new \LengthException('Najednou lze exportovat maximálně 100 faktur.');
        }

        return $ids;
    }
}
