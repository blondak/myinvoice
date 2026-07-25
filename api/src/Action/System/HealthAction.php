<?php

declare(strict_types=1);

namespace MyInvoice\Action\System;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Cache\RedisProbe;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\PasskeyService;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Auth\SessionLockPolicy;
use MyInvoice\Service\Update\VersionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class HealthAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly RedisProbe $redis,
        private readonly SecretEncryption $crypto,
        private readonly VersionService $version,
        private readonly PasskeyService $passkeys,
        private readonly MfaPolicyService $mfaPolicy,
        private readonly SessionLockPolicy $sessionLockPolicy,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $payload = [
            'status'  => 'ok',
            'version' => $this->version->getCurrentVersion(),
            'db'      => $this->db->ping(),
            'redis'   => $this->redis->isAvailable(),
            'time'    => date(\DateTimeInterface::ATOM),
        ];

        // Diagnostické warningy (např. slabý fallback secret_encryption_key) jen
        // pro přihlášené — anonymnímu volajícímu (Docker healthcheck, monitoring)
        // neprozrazujeme detaily konfigurace.
        if ($request->getAttribute(AuthMiddleware::ATTR_USER) !== null) {
            $warnings = [];
            $keyWarning = $this->crypto->validateKey();
            if ($keyWarning !== null) {
                $warnings[] = [
                    'code' => 'secret_encryption_key',
                    'message' => $keyWarning,
                ];
            }
            if ($this->mfaPolicy->isMethodAllowed('passkey')
                && !$this->passkeys->isAvailable()
            ) {
                $warnings[] = [
                    'code' => 'webauthn_configuration',
                    'message' => $this->passkeys->configurationError()
                        ?? 'Konfigurace WebAuthn není platná.',
                ];
            }
            $lockWarning = $this->sessionLockPolicy->configurationWarning();
            if ($lockWarning !== null) {
                $warnings[] = [
                    'code' => 'session_lock_configuration',
                    'message' => $lockWarning,
                ];
            }
            $payload['warnings'] = $warnings;
        }

        return Json::ok($response, $payload);
    }
}
