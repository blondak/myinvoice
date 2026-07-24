<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class SessionLockService
{
    public function __construct(
        private readonly Connection $db,
        private readonly SessionManager $sessions,
        private readonly SessionLockPolicy $policy,
        private readonly SecurityClock $clock,
        private readonly WebAuthnCeremonyStore $ceremonies,
    ) {}

    /**
     * Atomicky materializuje idle deadline. Zamčenou session nikdy neodemkne.
     */
    public function evaluate(string $token): SessionLockResult
    {
        return $this->transition($token, false, false);
    }

    /**
     * Signál skutečné aktivity. Pokud už deadline uplynul, nejprve session
     * jednosměrně zamkne a aktivitu nezapíše.
     */
    public function recordActivity(string $token): SessionLockResult
    {
        return $this->transition($token, true, false);
    }

    public function lockManually(string $token): SessionLockResult
    {
        return $this->transition($token, false, true);
    }

    private function transition(
        string $token,
        bool $recordActivity,
        bool $manualLock,
    ): SessionLockResult {
        if (!self::isTokenShapeValid($token)) {
            return SessionLockResult::missing();
        }
        if (!$this->policy->isEnabled() && !$manualLock) {
            [$snapshot, $effectiveTimeout] = $this->readWithoutIdleTransition($token);
            if (!$snapshot->sessionExists || $snapshot->locked || $effectiveTimeout === 0) {
                return $snapshot;
            }
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $cutoff = $this->clock->capture($pdo);
            $stmt = $pdo->prepare(
                'SELECT s.last_user_activity_at, s.locked_at, s.lock_reason,
                        u.session_lock_after_minutes
                   FROM sessions s
                   JOIN users u ON u.id = s.user_id AND u.is_active = 1
                  WHERE s.id = ?
                    AND s.expires_at > FROM_UNIXTIME(?)
                    AND s.replaced_at IS NULL
                    AND s.revoked_at IS NULL
                  FOR UPDATE'
            );
            $stmt->execute([$token, $cutoff->epochSeconds]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                $pdo->commit();
                $this->sessions->invalidateCache($token);
                return SessionLockResult::missing();
            }

            $lastActivity = self::parseUtc((string) $row['last_user_activity_at']);
            if ($row['locked_at'] !== null) {
                $pdo->commit();
                return SessionLockResult::locked(
                    $lastActivity,
                    self::parseUtc((string) $row['locked_at']),
                    (string) $row['lock_reason'],
                    false,
                    $cutoff->utc,
                );
            }

            $now = $cutoff->utc;
            $timeoutSeconds = $this->policy->effectiveTimeoutSeconds(
                self::nullableTimeout($row['session_lock_after_minutes'] ?? null),
            );
            $idleExpired = $timeoutSeconds > 0
                && $lastActivity <= $now->modify(sprintf('-%d seconds', $timeoutSeconds));

            if ($manualLock || $idleExpired) {
                $reason = $manualLock ? 'manual' : 'idle';
                $update = $pdo->prepare(
                    'UPDATE sessions
                        SET locked_at = ?, lock_reason = ?
                      WHERE id = ? AND locked_at IS NULL AND replaced_at IS NULL AND revoked_at IS NULL'
                );
                $update->execute([$cutoff->utcSql, $reason, $token]);
                if ($update->rowCount() !== 1) {
                    throw new \RuntimeException('Session lock transition se nepodařil.');
                }
                $this->ceremonies->cancelForSessionAt($token, $cutoff->utcSql);
                $pdo->commit();
                $this->sessions->invalidateCache($token);
                return SessionLockResult::locked($lastActivity, $now, $reason, true, $now);
            }

            if ($recordActivity && $now > $lastActivity) {
                $update = $pdo->prepare(
                    'UPDATE sessions
                        SET last_user_activity_at = ?
                      WHERE id = ? AND locked_at IS NULL AND replaced_at IS NULL AND revoked_at IS NULL'
                );
                $update->execute([$cutoff->utcSql, $token]);
                if ($update->rowCount() !== 1) {
                    throw new \RuntimeException('Aktivitu session se nepodařilo uložit.');
                }
                $lastActivity = $now;
            }

            $pdo->commit();
            if ($recordActivity) {
                $this->sessions->invalidateCache($token);
            }
            return SessionLockResult::active($lastActivity, $now);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Při vypnutém automatickém zámku není co materializovat ani zapisovat.
     * Autoritativní čtení ale zachovává fail-closed kontrolu zániku session
     * a respektuje případný ruční zámek.
     */
    /**
     * @return array{SessionLockResult,int}
     */
    private function readWithoutIdleTransition(string $token): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.last_user_activity_at, s.locked_at, s.lock_reason,
                    u.session_lock_after_minutes,
                    UTC_TIMESTAMP(6) AS evaluated_at
               FROM sessions s
               JOIN users u ON u.id = s.user_id AND u.is_active = 1
              WHERE s.id = ?
                AND s.expires_at > CURRENT_TIMESTAMP(6)
                AND s.replaced_at IS NULL
                AND s.revoked_at IS NULL
              LIMIT 1'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            $this->sessions->invalidateCache($token);
            return [SessionLockResult::missing(), 0];
        }

        $lastActivity = self::parseUtc((string) $row['last_user_activity_at']);
        $evaluatedAt = self::parseUtc((string) $row['evaluated_at']);
        $effectiveTimeout = $this->policy->effectiveTimeoutMinutes(
            self::nullableTimeout($row['session_lock_after_minutes'] ?? null),
        );
        if ($row['locked_at'] !== null) {
            return [
                SessionLockResult::locked(
                    $lastActivity,
                    self::parseUtc((string) $row['locked_at']),
                    (string) $row['lock_reason'],
                    false,
                    $evaluatedAt,
                ),
                $effectiveTimeout,
            ];
        }
        return [SessionLockResult::active($lastActivity, $evaluatedAt), $effectiveTimeout];
    }

    private static function isTokenShapeValid(string $token): bool
    {
        return strlen($token) === 64 && ctype_xdigit($token);
    }

    private static function nullableTimeout(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private static function parseUtc(string $time): \DateTimeImmutable
    {
        foreach (['!Y-m-d H:i:s.u', '!Y-m-d H:i:s'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $time, new \DateTimeZone('UTC'));
            if ($parsed !== false) {
                return $parsed;
            }
        }
        throw new \UnexpectedValueException('Session obsahuje neplatný UTC čas aktivity.');
    }
}
