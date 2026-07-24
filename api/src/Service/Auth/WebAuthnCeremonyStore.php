<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use Psr\Clock\ClockInterface;

final class WebAuthnCeremonyStore
{
    public const PURPOSE_REGISTER = 'passkey.register';
    public const PURPOSE_LOGIN = 'passkey.login';
    public const PURPOSE_STEP_UP = 'passkey.step_up';
    public const PURPOSE_UNLOCK = 'session.unlock';

    private const PURPOSES = [
        self::PURPOSE_REGISTER,
        self::PURPOSE_LOGIN,
        self::PURPOSE_STEP_UP,
        self::PURPOSE_UNLOCK,
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @param array<string,mixed> $options
     */
    public function create(
        string $purpose,
        int $userId,
        ?string $sessionToken,
        ?string $operation,
        string $challenge,
        array $options,
        string $ip,
        string $userAgent,
        int $ttlSeconds = 300,
    ): string {
        $operation = $operation !== null ? trim($operation) : null;
        self::validateContext($purpose, $sessionToken, $operation);
        if (strlen($challenge) < 16 || strlen($challenge) > 64) {
            throw new \InvalidArgumentException('WebAuthn challenge musí mít 16 až 64 bajtů.');
        }
        if ($ttlSeconds < 1 || $ttlSeconds > 300) {
            throw new \InvalidArgumentException('WebAuthn flow může být platné nejvýše 300 sekund.');
        }

        $packedIp = @inet_pton($ip);
        if ($packedIp === false) {
            throw new \InvalidArgumentException('Neplatná IP adresa WebAuthn flow.');
        }

        $token = self::randomToken();
        $now = $this->clock->now();
        $expiresAt = $now->modify("+{$ttlSeconds} seconds");
        $optionsJson = json_encode($options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO webauthn_ceremonies
                (flow_token_hash, challenge, purpose, operation, user_id, session_id_hash,
                 options_json, ip, user_agent, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            hash('sha256', $token, true),
            $challenge,
            $purpose,
            $operation,
            $userId,
            $sessionToken !== null ? hash('sha256', $sessionToken, true) : null,
            $optionsJson,
            $packedIp,
            mb_substr($userAgent, 0, 255),
            self::formatTime($expiresAt),
            self::formatTime($now),
        ]);

        return $token;
    }

    public function consume(
        string $flowToken,
        string $expectedPurpose,
        int $expectedUserId,
        ?string $expectedSessionToken,
        ?string $expectedOperation,
    ): WebAuthnCeremony {
        return $this->consumeBound(
            $flowToken,
            $expectedPurpose,
            $expectedUserId,
            $expectedSessionToken,
            $expectedOperation,
        );
    }

    public function consumeLogin(string $flowToken): WebAuthnCeremony
    {
        return $this->consumeBound(
            $flowToken,
            self::PURPOSE_LOGIN,
            null,
            null,
            null,
        );
    }

    /**
     * Zneplatní rozpracovanou registraci a step-up při zamknutí session.
     * Unlock flow se vytváří až nad zamčenou session a zůstává nedotčené.
     */
    public function cancelForSession(string $sessionToken): int
    {
        if ($sessionToken === '') {
            return 0;
        }
        $stmt = $this->db->pdo()->prepare(
            'UPDATE webauthn_ceremonies
                SET used_at = ?
              WHERE session_id_hash = ?
                AND purpose IN (?, ?)
                AND used_at IS NULL'
        );
        $stmt->execute([
            self::formatTime($this->clock->now()),
            hash('sha256', $sessionToken, true),
            self::PURPOSE_REGISTER,
            self::PURPOSE_STEP_UP,
        ]);
        return $stmt->rowCount();
    }

    private function consumeBound(
        string $flowToken,
        string $expectedPurpose,
        ?int $expectedUserId,
        ?string $expectedSessionToken,
        ?string $expectedOperation,
    ): WebAuthnCeremony {
        self::validateContext($expectedPurpose, $expectedSessionToken, $expectedOperation);
        if (!self::isTokenShapeValid($flowToken)) {
            throw new OneTimeTokenException('Neplatné nebo spotřebované WebAuthn flow.');
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT flow_token_hash, challenge, purpose, operation, user_id, session_id_hash,
                        options_json, expires_at, used_at
                   FROM webauthn_ceremonies
                  WHERE flow_token_hash = ?
                  FOR UPDATE'
            );
            $stmt->execute([hash('sha256', $flowToken, true)]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row !== false && $row['used_at'] === null) {
                $markUsed = $pdo->prepare(
                    'UPDATE webauthn_ceremonies SET used_at = ? WHERE flow_token_hash = ? AND used_at IS NULL'
                );
                $markUsed->execute([self::formatTime($this->clock->now()), $row['flow_token_hash']]);
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
            || ($expectedUserId !== null && (int) $row['user_id'] !== $expectedUserId)
            || !hash_equals((string) $row['purpose'], $expectedPurpose)
            || !self::nullableHashMatches($row['session_id_hash'], $expectedSessionToken)
            || !self::nullableStringMatches($row['operation'], $expectedOperation)
        ) {
            throw new OneTimeTokenException('Neplatné nebo spotřebované WebAuthn flow.');
        }

        $options = json_decode((string) $row['options_json'], true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($options)) {
            throw new \UnexpectedValueException('WebAuthn flow obsahuje neplatné options.');
        }

        return new WebAuthnCeremony(
            (string) $row['purpose'],
            (int) $row['user_id'],
            $row['operation'] !== null ? (string) $row['operation'] : null,
            (string) $row['challenge'],
            $options,
        );
    }

    private static function validateContext(string $purpose, ?string $sessionToken, ?string $operation): void
    {
        if (!in_array($purpose, self::PURPOSES, true)) {
            throw new \InvalidArgumentException('Neznámý účel WebAuthn flow.');
        }
        if ($purpose === self::PURPOSE_LOGIN && $sessionToken !== null) {
            throw new \InvalidArgumentException('Login flow nesmí být vázané na existující session.');
        }
        if ($purpose !== self::PURPOSE_LOGIN && ($sessionToken === null || $sessionToken === '')) {
            throw new \InvalidArgumentException('WebAuthn flow musí být vázané na session.');
        }
        if ($purpose === self::PURPOSE_STEP_UP && ($operation === null || trim($operation) === '')) {
            throw new \InvalidArgumentException('Step-up flow vyžaduje účelovou operaci.');
        }
        if ($purpose !== self::PURPOSE_STEP_UP && $operation !== null) {
            throw new \InvalidArgumentException('Operaci smí nést pouze step-up flow.');
        }
        if ($operation !== null && strlen($operation) > 190) {
            throw new \InvalidArgumentException('Step-up operace je příliš dlouhá.');
        }
    }

    private static function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private static function isTokenShapeValid(string $token): bool
    {
        return strlen($token) === 43 && preg_match('/^[A-Za-z0-9_-]+$/D', $token) === 1;
    }

    private static function nullableHashMatches(mixed $storedHash, ?string $plaintext): bool
    {
        if ($storedHash === null || $plaintext === null) {
            return $storedHash === null && $plaintext === null;
        }
        return hash_equals((string) $storedHash, hash('sha256', $plaintext, true));
    }

    private static function nullableStringMatches(mixed $stored, ?string $expected): bool
    {
        if ($stored === null || $expected === null) {
            return $stored === null && $expected === null;
        }
        return hash_equals((string) $stored, $expected);
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
            throw new \UnexpectedValueException('Neplatný UTC čas WebAuthn flow.');
        }
        return $parsed;
    }
}
