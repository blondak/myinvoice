<?php

declare(strict_types=1);

namespace MyInvoice\Action\Report;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * DPH přiznání DPHDP3 endpoints:
 *   GET /api/reports/dphdp3/preview?year=2026&month=5  — JSON summary (řádky + warnings)
 *   GET /api/reports/dphdp3?year=2026&month=5          — XML download
 *
 * Permissions: admin nebo accountant.
 *
 * ⚠️ Vygenerované XML je pomůcka. Před odesláním ověřit s účetní/poradcem.
 */
final class DphPriznaniAction
{
    public function __construct(
        private readonly DphPriznaniBuilder $builder,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function preview(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant'], true)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $year  = (int) ($q['year']  ?? date('Y'));
        $month = (int) ($q['month'] ?? date('n'));
        if ($month < 1 || $month > 12 || $year < 2020 || $year > 2050) {
            return Json::error($response, 'validation_failed', 'Neplatný rok/měsíc.', 400);
        }

        try {
            $result = $this->builder->build($supplierId, $year, $month);
        } catch (\Throwable $e) {
            return Json::error($response, 'build_failed', $e->getMessage(), 500);
        }

        return Json::ok($response, [
            'summary'  => $result['summary'],
            'warnings' => $result['warnings'],
        ]);
    }

    public function download(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant'], true)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $year  = (int) ($q['year']  ?? date('Y'));
        $month = (int) ($q['month'] ?? date('n'));
        if ($month < 1 || $month > 12 || $year < 2020 || $year > 2050) {
            return Json::error($response, 'validation_failed', 'Neplatný rok/měsíc.', 400);
        }

        try {
            $result = $this->builder->build($supplierId, $year, $month);
        } catch (\Throwable $e) {
            return Json::error($response, 'build_failed', $e->getMessage(), 500);
        }

        $userId = (int) ($user['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('report.dphdp3_downloaded', $userId, null, null, [
            'period'            => sprintf('%04d-%02d', $year, $month),
            'total_vat_output'  => $result['summary']['total_vat_output'] ?? 0,
            'total_vat_input'   => $result['summary']['total_vat_input']  ?? 0,
        ], $ip, $request->getHeaderLine('User-Agent'));

        $filename = sprintf('dphdp3-%04d-%02d.xml', $year, $month);
        $response->getBody()->write($result['xml']);
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-store');
    }
}
