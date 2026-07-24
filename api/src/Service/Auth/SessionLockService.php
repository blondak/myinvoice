<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use Psr\Clock\ClockInterface;

final class SessionLockService
{
    public function __construct(
        private readonly Connection $db,
        private readonly SessionManager $sessions,
        private readonly SessionLockPolicy $policy,
        private readonly ClockInterface $clock,
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

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT last_user_activity_at, locked_at, lock_reason
                   FROM sessions
                  WHERE id = ?
                    AND expires_at > NOW()
                    AND replaced_at IS NULL
                    AND revoked_at IS NULL
                  FOR UPDATE'
            );
            $stmt->execute([$token]);
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
                );
            }

            $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
            $idleExpired = $this->policy->isEnabled()
                && $lastActivity <= $now->modify(sprintf('-%d seconds', $this->policy->timeoutSeconds()));

            if ($manualLock || $idleExpired) {
                $reason = $manualLock ? 'manual' : 'idle';
                $update = $pdo->prepare(
                    'UPDATE sessions
                        SET locked_at = ?, lock_reason = ?
                      WHERE id = ? AND locked_at IS NULL AND replaced_at IS NULL AND revoked_at IS NULL'
                );
                $update->execute([self::formatUtc($now), $reason, $token]);
                if ($update->rowCount() !== 1) {
                    throw new \RuntimeException('Session lock transition se nepodařil.');
                }
                $this->ceremonies->cancelForSession($token);
                $pdo->commit();
                $this->sessions->invalidateCache($token);
                return SessionLockResult::locked($lastActivity, $now, $reason, true);
            }

            if ($recordActivity && $now > $lastActivity) {
                $update = $pdo->prepare(
                    'UPDATE sessions
                        SET last_user_activity_at = ?
                      WHERE id = ? AND locked_at IS NULL AND replaced_at IS NULL AND revoked_at IS NULL'
                );
                $update->execute([self::formatUtc($now), $token]);
                if ($update->rowCount() !== 1) {
                    throw new \RuntimeException('Aktivitu session se nepodařilo uložit.');
                }
                $lastActivity = $now;
            }

            $pdo->commit();
            if ($recordActivity) {
                $this->sessions->invalidateCache($token);
            }
            return SessionLockResult::active($lastActivity);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function isTokenShapeValid(string $token): bool
    {
        return strlen($token) === 64 && ctype_xdigit($token);
    }

    private static function formatUtc(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
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
