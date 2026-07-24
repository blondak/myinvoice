<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use Psr\Clock\ClockInterface;

final class MfaStepUpProofStore
{
    private const AUTH_METHODS = ['passkey', 'totp'];

    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
    ) {}

    public function issue(
        int $userId,
        string $sessionToken,
        string $operation,
        string $authMethod,
        ?int $authCredentialId = null,
        int $ttlSeconds = 300,
    ): string {
        $operation = trim($operation);
        if ($sessionToken === '' || $operation === '' || strlen($operation) > 190) {
            throw new \InvalidArgumentException('Step-up proof vyžaduje session a platnou operaci.');
        }
        if (!in_array($authMethod, self::AUTH_METHODS, true)) {
            throw new \InvalidArgumentException('Nepodporovaná metoda step-up ověření.');
        }
        if (($authMethod === 'passkey') !== ($authCredentialId !== null)) {
            throw new \InvalidArgumentException('Passkey step-up musí být vázaný na credential.');
        }
        if ($ttlSeconds < 1 || $ttlSeconds > 300) {
            throw new \InvalidArgumentException('Step-up proof může být platný nejvýše 300 sekund.');
        }

        $token = self::randomToken();
        $now = $this->clock->now();
        $expiresAt = $now->modify("+{$ttlSeconds} seconds");
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO mfa_step_up_proofs
                (token_hash, user_id, session_id_hash, operation, auth_method,
                 auth_credential_id, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            hash('sha256', $token, true),
            $userId,
            hash('sha256', $sessionToken, true),
            $operation,
            $authMethod,
            $authCredentialId,
            self::formatTime($expiresAt),
            self::formatTime($now),
        ]);

        return $token;
    }

    public function consume(
        string $proofToken,
        int $expectedUserId,
        string $expectedSessionToken,
        string $expectedOperation,
    ): MfaStepUpProof {
        $expectedOperation = trim($expectedOperation);
        if (!self::isTokenShapeValid($proofToken)
            || $expectedSessionToken === ''
            || $expectedOperation === ''
        ) {
            throw new OneTimeTokenException('Neplatný nebo spotřebovaný step-up proof.');
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT token_hash, user_id, session_id_hash, operation, auth_method,
                        auth_credential_id, expires_at, used_at
                   FROM mfa_step_up_proofs
                  WHERE token_hash = ?
                  FOR UPDATE'
            );
            $stmt->execute([hash('sha256', $proofToken, true)]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row !== false && $row['used_at'] === null) {
                $markUsed = $pdo->prepare(
                    'UPDATE mfa_step_up_proofs SET used_at = ? WHERE token_hash = ? AND used_at IS NULL'
                );
                $markUsed->execute([self::formatTime($this->clock->now()), $row['token_hash']]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        if ($row === false
            || $row['used_at'] !== null
            || self::parseTime((string) $row['expires_at']) <= $this->clock->now()
            || (int) $row['user_id'] !== $expectedUserId
            || !hash_equals((string) $row['session_id_hash'], hash('sha256', $expectedSessionToken, true))
            || !hash_equals((string) $row['operation'], $expectedOperation)
        ) {
            throw new OneTimeTokenException('Neplatný nebo spotřebovaný step-up proof.');
        }

        return new MfaStepUpProof(
            (int) $row['user_id'],
            (string) $row['operation'],
            (string) $row['auth_method'],
            $row['auth_credential_id'] !== null ? (int) $row['auth_credential_id'] : null,
        );
    }

    private static function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private static function isTokenShapeValid(string $token): bool
    {
        return strlen($token) === 43 && preg_match('/^[A-Za-z0-9_-]+$/D', $token) === 1;
    }

    private static function formatTime(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function parseTime(string $time): \DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s.u',
            $time,
            new \DateTimeZone('UTC'),
        );
        if ($parsed === false) {
            throw new \UnexpectedValueException('Neplatný UTC čas step-up proofu.');
        }
        return $parsed;
    }
}
