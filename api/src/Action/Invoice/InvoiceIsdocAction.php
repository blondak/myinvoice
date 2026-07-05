<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Export\IsdocExporter;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/invoices/{id}/isdoc — ISDOC XML jedné vystavené faktury.
 *
 * Doplňuje symetrii s /api/purchase-invoices/{id}/isdoc (přijaté ho mají,
 * vystavené dosud jen hromadně přes admin export). Pro integrace: účetní
 * software zákazníka si stáhne strojově čitelnou fakturu bez ZIP obalu.
 *
 * Supplier scope: faktura musí patřit aktuálnímu dodavateli (X-Supplier-Id),
 * jinak 404 — stejně jako GetInvoiceAction.
 */
final class InvoiceIsdocAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly IsdocExporter $isdoc,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id  = (int) ($args['id'] ?? 0);
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);

        $invoice = $this->repo->find($id);
        if ($invoice === null || (int) ($invoice['supplier_id'] ?? 0) !== $sid) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        // Draft nemá číslo (varsymbol) — ISDOC dává smysl až pro vystavený doklad.
        if (($invoice['status'] ?? '') === 'draft') {
            return Json::error($response, 'validation_failed', 'Koncept nelze exportovat do ISDOC — nejdřív fakturu vystavte.', 400);
        }

        try {
            $xml = $this->isdoc->buildXml($invoice);
        } catch (\Throwable $e) {
            return Json::error($response, 'export_failed', $e->getMessage(), 500);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoice.isdoc_exported', isset($user['id']) ? (int) $user['id'] : null, 'invoice', $id,
            null, $ip, $request->getHeaderLine('User-Agent'), $sid);

        $vs = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) ($invoice['varsymbol'] ?? $id));
        $response->getBody()->write($xml);
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="Faktura-' . $vs . '.isdoc"')
            ->withHeader('Cache-Control', 'no-store');
    }
}
