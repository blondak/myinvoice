<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

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
        private readonly SecurityClock $securityClock,
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
        if (strlen($challenge) < 32 || strlen($challenge) > 64) {
            throw new \InvalidArgumentException('WebAuthn challenge musí mít 32 až 64 bajtů.');
        }
        if ($ttlSeconds < 1 || $ttlSeconds > 300) {
            throw new \InvalidArgumentException('WebAuthn flow může být platné nejvýše 300 sekund.');
        }

        $packedIp = @inet_pton($ip);
        if ($packedIp === false) {
            throw new \InvalidArgumentException('Neplatná IP adresa WebAuthn flow.');
        }

        $token = self::randomToken();
        $optionsJson = json_encode($options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $cutoff = $this->securityClock->capture($pdo);
            $stmt = $pdo->prepare(
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
                self::formatTime($cutoff->plusSeconds($ttlSeconds)),
                $cutoff->utcSql,
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

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

    public function peekLoginUserId(string $flowToken): ?int
    {
        if (!self::isTokenShapeValid($flowToken)) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT user_id
               FROM webauthn_ceremonies
              WHERE flow_token_hash = ? AND purpose = ?
              LIMIT 1'
        );
        $stmt->execute([hash('sha256', $flowToken, true), self::PURPOSE_LOGIN]);
        $userId = $stmt->fetchColumn();
        return $userId !== false ? (int) $userId : null;
    }

    /**
     * Zneplatní rozpracovanou registraci a step-up při zamknutí session.
     * Unlock flow se vytváří až nad zamčenou session a zůstává nedotčené.
     */
    public function cancelForSession(string $sessionToken): int
    {
        $cutoff = $this->securityClock->capture($this->db->pdo());
        return $this->cancelForSessionAt($sessionToken, $cutoff->utcSql);
    }

    public function cancelForSessionAt(string $sessionToken, string $usedAtUtc): int
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
            $usedAtUtc,
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
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $cutoff = $this->securityClock->capture($pdo);
            $ceremony = $this->consumeInTransaction(
                $pdo,
                $cutoff,
                $flowToken,
                $expectedPurpose,
                $expectedUserId,
                $expectedSessionToken,
                $expectedOperation,
            );
            $pdo->commit();
            return $ceremony;
        } catch (OneTimeTokenException $e) {
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function consumeInTransaction(
        PDO $pdo,
        SecurityTime $cutoff,
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
            $markUsed->execute([$cutoff->utcSql, $row['flow_token_hash']]);
        }
        if ($row === false
            || $row['used_at'] !== null
            || self::parseTime((string) $row['expires_at']) <= $cutoff->utc
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

    /**
     * Spotřebuje první verify pokus i tehdy, když navazující kontrola uživatele,
     * session nebo účelu selhala dřív, než šlo načíst vázaná ceremony data.
     */
    public function markAttemptUsedInTransaction(
        PDO $pdo,
        SecurityTime $cutoff,
        string $flowToken,
    ): bool {
        if (!$pdo->inTransaction()) {
            throw new \LogicException('Spotřeba WebAuthn pokusu vyžaduje aktivní transakci.');
        }
        if (!self::isTokenShapeValid($flowToken)) {
            return false;
        }

        $flowTokenHash = hash('sha256', $flowToken, true);
        $stmt = $pdo->prepare(
            'SELECT used_at
               FROM webauthn_ceremonies
              WHERE flow_token_hash = ?
              FOR UPDATE'
        );
        $stmt->execute([$flowTokenHash]);
        $usedAt = $stmt->fetchColumn();
        if ($usedAt === false || $usedAt !== null) {
            return false;
        }

        $markUsed = $pdo->prepare(
            'UPDATE webauthn_ceremonies
                SET used_at = ?
              WHERE flow_token_hash = ? AND used_at IS NULL'
        );
        $markUsed->execute([$cutoff->utcSql, $flowTokenHash]);
        return $markUsed->rowCount() === 1;
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
