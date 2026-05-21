<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin\Import;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Import\FakturoidClient;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET    /api/admin/imports/fakturoid/credentials  — status
 * PUT    /api/admin/imports/fakturoid/credentials  — set + test (BasicAuth handshake)
 * DELETE /api/admin/imports/fakturoid/credentials  — remove
 *
 * Body PUT: { slug, email, api_key }
 */
final class FakturoidCredentialsAction
{
    public function __construct(
        private readonly FakturoidClient $fakturoid,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function status(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        $supplierId = SupplierGuard::currentId($request);
        $creds = $this->fakturoid->getCredentials($supplierId);
        return Json::ok($response, [
            'configured' => $creds !== null,
            'slug'       => $creds['slug']  ?? null,
            'email'      => $creds['email'] ?? null,
        ]);
    }

    public function update(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        $supplierId = SupplierGuard::currentId($request);

        $body = (array) ($request->getParsedBody() ?? []);
        $slug   = trim((string) ($body['slug']   ?? ''));
        $email  = trim((string) ($body['email']  ?? ''));
        $apiKey = (string) ($body['api_key'] ?? '');

        if ($slug === '' || $email === '' || $apiKey === '') {
            return Json::error($response, 'validation_failed', 'slug, email i api_key jsou povinné.', 400);
        }
        if (strlen($slug) > 64 || strlen($email) > 255 || strlen($apiKey) > 512) {
            return Json::error($response, 'validation_failed', 'Credentials přesahují délkový limit.', 400);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Json::error($response, 'validation_failed', 'Neplatný formát emailu.', 400);
        }

        $this->fakturoid->setCredentials($supplierId, $slug, $email, $apiKey);
        $userId = (int) ($user['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('import.fakturoid_credentials_set', $userId, 'supplier', $supplierId, [
            'slug' => $slug, 'email' => $email,
        ], $ip, $request->getHeaderLine('User-Agent'));

        $test = $this->fakturoid->testConnection($supplierId);
        return Json::ok($response, [
            'saved'        => true,
            'test_ok'      => $test['ok'],
            'test_error'   => $test['ok'] ? null : ($test['error'] ?? null),
            'account_name' => $test['account_name'] ?? null,
        ]);
    }

    public function delete(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        $supplierId = SupplierGuard::currentId($request);
        $this->fakturoid->setCredentials($supplierId, '', '', '');
        $userId = (int) ($user['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('import.fakturoid_credentials_removed', $userId, 'supplier', $supplierId, null,
            $ip, $request->getHeaderLine('User-Agent'));
        return Json::ok($response, ['ok' => true]);
    }
}
