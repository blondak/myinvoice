<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use MyInvoice\Infrastructure\Cache\RedisFactory;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use Predis\Client as RedisClient;
use Psr\Clock\ClockInterface;

/**
 * Server-side browser sessions.
 *
 * MariaDB je autorita pro expiraci, revokaci, session lineage a lock stav.
 * Redis je pouze best-effort cache; jeho obsah nikdy sám neautorizuje request.
 */
final class SessionManager
{
    public function __construct(
        private readonly Connection $db,
        private readonly RedisFactory $redis,
        private readonly Config $config,
        private readonly ClockInterface $clock,
        private readonly SecurityClock $securityClock,
    ) {}

    /**
     * @return array{token:string,csrf_token:string,expires_at:int,issued_at:\DateTimeImmutable}
     */
    public function create(
        int $userId,
        string $ip,
        string $userAgent,
        ?SessionAuthContext $authContext = null,
    ): array {
        $authContext ??= SessionAuthContext::legacy();
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $cutoff = $this->securityClock->capture($pdo);
            $session = $this->createInTransaction(
                $pdo,
                $cutoff,
                $userId,
                $ip,
                $userAgent,
                $authContext,
            );
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        $this->deleteCache($session['token']);
        return $session;
    }

    /**
     * Volající vlastní transakci a musí mít zamčeného uživatele, pokud jde
     * o součást širšího bezpečnostního přechodu.
     *
     * @return array{token:string,csrf_token:string,expires_at:int,issued_at:\DateTimeImmutable}
     */
    public function createInTransaction(
        PDO $pdo,
        SecurityTime $cutoff,
        int $userId,
        string $ip,
        string $userAgent,
        SessionAuthContext $authContext,
    ): array {
        if (!$pdo->inTransaction() || $userId < 1) {
            throw new \LogicException('Vydání session vyžaduje aktivní transakci a uživatele.');
        }
        $packedIp = @inet_pton($ip);
        if ($packedIp === false) {
            throw new \InvalidArgumentException('Neplatná IP adresa session.');
        }
        $lifetimeDays = (int) $this->config->get('session.lifetime_days', 30);
        if ($lifetimeDays < 1 || $lifetimeDays > 365) {
            throw new \InvalidArgumentException('session.lifetime_days musí být 1 až 365.');
        }

        $token = bin2hex(random_bytes(32));
        $csrf = bin2hex(random_bytes(32));
        $familyId = random_bytes(32);
        $expiresAt = $cutoff->epochSeconds + ($lifetimeDays * 86400);
        $stmt = $pdo->prepare(
            'INSERT INTO sessions
                (id, user_id, csrf_token, ip, user_agent, created_at, last_seen, expires_at,
                 auth_method, assurance_level, mfa_verified_at, auth_credential_id,
                 last_user_activity_at, session_family_id, generation)
             VALUES (?, ?, ?, ?, ?, FROM_UNIXTIME(?), FROM_UNIXTIME(?), FROM_UNIXTIME(?),
                     ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $token,
            $userId,
            $csrf,
            $packedIp,
            mb_substr($userAgent, 0, 255),
            $cutoff->epochSeconds,
            $cutoff->epochSeconds,
            $expiresAt,
            $authContext->authMethod,
            $authContext->assuranceLevel,
            self::nullableUtc($authContext->mfaVerifiedAt),
            $authContext->authCredentialId,
            $cutoff->utcSql,
            $familyId,
            1,
        ]);

        return [
            'token' => $token,
            'csrf_token' => $csrf,
            'expires_at' => $expiresAt,
            'issued_at' => $cutoff->utc,
        ];
    }

    /**
     * Načte pouze aktuální aktivní generaci session. Bezpečnostní stav se vždy
     * čte z MariaDB; stale Redis payload nikdy request neautorizuje.
     *
     * @return array<string,mixed>|null
     */
    public function load(string $token): ?array
    {
        if (!self::isTokenShapeValid($token)) {
            return null;
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT id, user_id, csrf_token, ip, user_agent,
                    UNIX_TIMESTAMP(created_at) AS created_at,
                    UNIX_TIMESTAMP(last_seen) AS last_seen,
                    UNIX_TIMESTAMP(expires_at) AS expires_at,
                    auth_method, assurance_level, mfa_verified_at, auth_credential_id,
                    last_user_activity_at, locked_at, lock_reason,
                    last_unlock_at, last_unlock_method,
                    HEX(session_family_id) AS session_family_id,
                    generation, replaced_at, revoked_at
               FROM sessions
              WHERE id = ?
                AND expires_at > NOW()
                AND replaced_at IS NULL
                AND revoked_at IS NULL
              LIMIT 1'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            $this->deleteCache($token);
            return null;
        }

        $data = self::hydrate($row);
        $this->writeCache($token, $data);
        return $data;
    }

    /**
     * Provozní touch nesmí měnit lidskou aktivitu ani absolutní expiraci.
     */
    public function touch(string $token): void
    {
        if (!self::isTokenShapeValid($token)) {
            return;
        }
        $stmt = $this->db->pdo()->prepare(
            'UPDATE sessions
                SET last_seen = NOW()
              WHERE id = ? AND expires_at > NOW() AND replaced_at IS NULL AND revoked_at IS NULL'
        );
        $stmt->execute([$token]);
        $this->deleteCache($token);
    }

    /**
     * Revokuje celou lineage i při logoutu přes starší nahrazenou generaci.
     */
    public function destroy(string $token): void
    {
        if (!self::isTokenShapeValid($token)) {
            return;
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $cutoff = $this->securityClock->capture($pdo);
            $family = $pdo->prepare('SELECT session_family_id FROM sessions WHERE id = ? FOR UPDATE');
            $family->execute([$token]);
            $familyId = $family->fetchColumn();
            if (!is_string($familyId) || $familyId === '') {
                $pdo->commit();
                $this->deleteCache($token);
                return;
            }

            $tokens = $pdo->prepare('SELECT id FROM sessions WHERE session_family_id = ? FOR UPDATE');
            $tokens->execute([$familyId]);
            $familyTokens = array_values(array_map('strval', $tokens->fetchAll(PDO::FETCH_COLUMN) ?: []));

            $revoke = $pdo->prepare(
                'UPDATE sessions
                    SET revoked_at = COALESCE(revoked_at, ?)
                  WHERE session_family_id = ?'
            );
            $revoke->execute([$cutoff->utcSql, $familyId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        foreach ($familyTokens as $familyToken) {
            $this->deleteCache($familyToken);
        }
    }

    /**
     * Atomicky revokuje všechny session families uživatele. Volitelná výjimka
     * zachová celou family předložené aktuální session.
     */
    public function destroyAllForUser(int $userId, ?string $exceptToken = null): int
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $cutoff = $this->securityClock->capture($pdo);
            $user = $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
            $user->execute([$userId]);
            if ($user->fetchColumn() === false) {
                $pdo->commit();
                return 0;
            }

            $exceptFamily = null;
            if ($exceptToken !== null && self::isTokenShapeValid($exceptToken)) {
                $stmt = $pdo->prepare(
                    'SELECT session_family_id FROM sessions WHERE id = ? AND user_id = ? LIMIT 1'
                );
                $stmt->execute([$exceptToken, $userId]);
                $value = $stmt->fetchColumn();
                $exceptFamily = is_string($value) && $value !== '' ? $value : null;
            }

            $params = [$userId];
            $where = 'user_id = ? AND revoked_at IS NULL';
            if ($exceptFamily !== null) {
                $where .= ' AND session_family_id <> ?';
                $params[] = $exceptFamily;
            }

            $tokens = $pdo->prepare("SELECT id FROM sessions WHERE {$where} FOR UPDATE");
            $tokens->execute($params);
            $revokedTokens = array_values(array_map('strval', $tokens->fetchAll(PDO::FETCH_COLUMN) ?: []));

            if ($revokedTokens !== []) {
                $revoke = $pdo->prepare(
                    "UPDATE sessions SET revoked_at = ? WHERE {$where}"
                );
                $revoke->execute([$cutoff->utcSql, ...$params]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        foreach ($revokedTokens as $revokedToken) {
            $this->deleteCache($revokedToken);
        }
        return count($revokedTokens);
    }

    /**
     * Atomicky spotřebuje aktuální setup session, revokuje všechny ostatní
     * setup session uživatele a vydá novou plnou strong session v nové family.
     *
     * @return array{token:string,csrf_token:string,expires_at:int,issued_at:\DateTimeImmutable}
     */
    public function completeSetup(
        int $userId,
        string $setupToken,
        string $ip,
        string $userAgent,
        SessionAuthContext $authContext,
    ): array {
        if ($userId < 1
            || !self::isTokenShapeValid($setupToken)
            || $authContext->assuranceLevel !== 'strong'
        ) {
            throw new \InvalidArgumentException('Neplatný požadavek na dokončení MFA setupu.');
        }
        $packedIp = @inet_pton($ip);
        if ($packedIp === false) {
            throw new \InvalidArgumentException('Neplatná IP adresa session.');
        }
        $lifetimeDays = (int) $this->config->get('session.lifetime_days', 30);
        if ($lifetimeDays < 1 || $lifetimeDays > 365) {
            throw new \InvalidArgumentException('session.lifetime_days musí být 1 až 365.');
        }

        $token = bin2hex(random_bytes(32));
        $csrf = bin2hex(random_bytes(32));
        $familyId = random_bytes(32);
        $setupTokens = [];

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $cutoff = $this->securityClock->capture($pdo);
            $expiresAt = $cutoff->epochSeconds + ($lifetimeDays * 86400);
            $user = $pdo->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1 FOR UPDATE');
            $user->execute([$userId]);
            if ($user->fetchColumn() === false) {
                throw new \DomainException('Uživatel už není aktivní.');
            }

            $current = $pdo->prepare(
                'SELECT id
                   FROM sessions
                  WHERE id = ?
                    AND user_id = ?
                    AND assurance_level = ?
                    AND expires_at > FROM_UNIXTIME(?)
                    AND replaced_at IS NULL
                    AND revoked_at IS NULL
                  FOR UPDATE'
            );
            $current->execute([$setupToken, $userId, 'setup', $cutoff->epochSeconds]);
            if ($current->fetchColumn() === false) {
                throw new \DomainException('Setup session už není dostupná.');
            }

            $allSetup = $pdo->prepare(
                'SELECT id
                   FROM sessions
                  WHERE user_id = ? AND assurance_level = ? AND revoked_at IS NULL
                  FOR UPDATE'
            );
            $allSetup->execute([$userId, 'setup']);
            $setupTokens = array_values(array_map('strval', $allSetup->fetchAll(PDO::FETCH_COLUMN) ?: []));

            $revoke = $pdo->prepare(
                'UPDATE sessions
                    SET revoked_at = ?
                  WHERE user_id = ? AND assurance_level = ? AND revoked_at IS NULL'
            );
            $revoke->execute([$cutoff->utcSql, $userId, 'setup']);

            $insert = $pdo->prepare(
                'INSERT INTO sessions
                    (id, user_id, csrf_token, ip, user_agent, created_at, last_seen, expires_at,
                     auth_method, assurance_level, mfa_verified_at, auth_credential_id,
                     last_user_activity_at, session_family_id, generation)
                 VALUES (?, ?, ?, ?, ?, FROM_UNIXTIME(?), FROM_UNIXTIME(?), FROM_UNIXTIME(?),
                         ?, ?, ?, ?, ?, ?, 1)'
            );
            $insert->execute([
                $token,
                $userId,
                $csrf,
                $packedIp,
                mb_substr($userAgent, 0, 255),
                $cutoff->epochSeconds,
                $cutoff->epochSeconds,
                $expiresAt,
                $authContext->authMethod,
                $authContext->assuranceLevel,
                self::nullableUtc($authContext->mfaVerifiedAt),
                $authContext->authCredentialId,
                $cutoff->utcSql,
                $familyId,
            ]);
            if ($insert->rowCount() !== 1) {
                throw new \RuntimeException('Strong session se nepodařilo vytvořit.');
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        foreach ($setupTokens as $oldToken) {
            $this->deleteCache($oldToken);
        }
        $this->deleteCache($token);

        return [
            'token' => $token,
            'csrf_token' => $csrf,
            'expires_at' => $expiresAt,
            'issued_at' => $cutoff->utc,
        ];
    }

    /**
     * Atomicky nahradí zamčenou generaci novým ID a CSRF bez prodloužení
     * absolutní expirace nebo změny login assurance.
     *
     * @return array{token:string,csrf_token:string,expires_at:int,user_id:int,issued_at:\DateTimeImmutable}
     */
    public function rotateLocked(
        string $token,
        string $unlockMethod,
        ?int $authCredentialId = null,
    ): array {
        if (!self::isTokenShapeValid($token)
            || !in_array($unlockMethod, ['passkey'], true)
            || $authCredentialId === null
            || $authCredentialId < 1
        ) {
            throw new \InvalidArgumentException('Neplatný požadavek na rotaci zamčené session.');
        }

        $pdo = $this->db->pdo();
        $owner = $pdo->prepare('SELECT user_id FROM sessions WHERE id = ? LIMIT 1');
        $owner->execute([$token]);
        $userId = $owner->fetchColumn();
        if ($userId === false) {
            throw new \DomainException('Zamčená session už není dostupná.');
        }

        $pdo->beginTransaction();
        try {
            $cutoff = $this->securityClock->capture($pdo);
            $user = $pdo->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1 FOR UPDATE');
            $user->execute([(int) $userId]);
            if ($user->fetchColumn() === false) {
                throw new \DomainException('Uživatel už není aktivní.');
            }
            $rotated = $this->rotateLockedInTransaction(
                $pdo,
                $cutoff,
                $token,
                (int) $userId,
                $unlockMethod,
                $authCredentialId,
            );
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $this->deleteCache($token);
        $this->deleteCache($rotated['token']);
        return $rotated;
    }

    /**
     * @return array{token:string,csrf_token:string,expires_at:int,user_id:int,issued_at:\DateTimeImmutable}
     */
    public function rotateLockedInTransaction(
        PDO $pdo,
        SecurityTime $cutoff,
        string $token,
        int $userId,
        string $unlockMethod,
        int $authCredentialId,
    ): array {
        if (!$pdo->inTransaction()
            || !self::isTokenShapeValid($token)
            || $userId < 1
            || $authCredentialId < 1
            || !in_array($unlockMethod, ['passkey'], true)
        ) {
            throw new \InvalidArgumentException('Neplatný požadavek na rotaci zamčené session.');
        }

        $select = $pdo->prepare(
            'SELECT user_id, UNIX_TIMESTAMP(expires_at) AS expires_at
               FROM sessions
              WHERE id = ?
                AND user_id = ?
                AND expires_at > FROM_UNIXTIME(?)
                AND locked_at IS NOT NULL
                AND replaced_at IS NULL
                AND revoked_at IS NULL
              FOR UPDATE'
        );
        $select->execute([$token, $userId, $cutoff->epochSeconds]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \DomainException('Zamčená session už není dostupná.');
        }

        $credential = $pdo->prepare(
            'SELECT id
               FROM webauthn_credentials
              WHERE id = ? AND user_id = ? AND revoked_at IS NULL
              FOR UPDATE'
        );
        $credential->execute([$authCredentialId, $userId]);
        if ($credential->fetchColumn() === false) {
            throw new \DomainException('Passkey už není aktivní.');
        }

        $newToken = bin2hex(random_bytes(32));
        $newCsrf = bin2hex(random_bytes(32));
        $insert = $pdo->prepare(
            'INSERT INTO sessions
                (id, user_id, csrf_token, ip, user_agent, created_at, last_seen, expires_at,
                 auth_method, assurance_level, mfa_verified_at, auth_credential_id,
                 last_user_activity_at, locked_at, lock_reason, last_unlock_at,
                 last_unlock_method, session_family_id, generation, replaced_at, revoked_at)
             SELECT ?, user_id, ?, ip, user_agent, created_at, FROM_UNIXTIME(?), expires_at,
                    auth_method, assurance_level, mfa_verified_at, auth_credential_id,
                    ?, NULL, NULL, ?, ?, session_family_id, generation + 1, NULL, NULL
               FROM sessions
              WHERE id = ? AND user_id = ?
                AND locked_at IS NOT NULL AND replaced_at IS NULL AND revoked_at IS NULL'
        );
        $insert->execute([
            $newToken,
            $newCsrf,
            $cutoff->epochSeconds,
            $cutoff->utcSql,
            $cutoff->utcSql,
            $unlockMethod,
            $token,
            $userId,
        ]);
        if ($insert->rowCount() !== 1) {
            throw new \RuntimeException('Novou generaci session se nepodařilo vytvořit.');
        }

        $replace = $pdo->prepare(
            'UPDATE sessions
                SET replaced_at = ?
              WHERE id = ? AND user_id = ?
                AND locked_at IS NOT NULL AND replaced_at IS NULL AND revoked_at IS NULL'
        );
        $replace->execute([$cutoff->utcSql, $token, $userId]);
        if ($replace->rowCount() !== 1) {
            throw new \RuntimeException('Původní generaci session se nepodařilo nahradit.');
        }

        return [
            'token' => $newToken,
            'csrf_token' => $newCsrf,
            'expires_at' => (int) $row['expires_at'],
            'user_id' => $userId,
            'issued_at' => $cutoff->utc,
        ];
    }

    public function invalidateCache(string $token): void
    {
        $this->deleteCache($token);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function hydrate(array $row): array
    {
        $packedIp = (string) $row['ip'];
        $row['ip'] = $packedIp !== '' ? (@inet_ntop($packedIp) ?: '') : '';
        $row['user_id'] = (int) $row['user_id'];
        $row['created_at'] = (int) $row['created_at'];
        $row['last_seen'] = (int) $row['last_seen'];
        $row['expires_at'] = (int) $row['expires_at'];
        $row['auth_credential_id'] = $row['auth_credential_id'] !== null
            ? (int) $row['auth_credential_id']
            : null;
        $row['generation'] = (int) $row['generation'];
        $row['session_family_id'] = strtolower((string) $row['session_family_id']);
        unset($row['id']);
        return $row;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function writeCache(string $token, array $data): void
    {
        $redis = $this->cacheClient();
        if ($redis === null) {
            return;
        }
        $ttl = (int) ($data['expires_at'] ?? 0) - $this->clock->now()->getTimestamp();
        try {
            if ($ttl <= 0) {
                $redis->del('sess:' . $token);
                return;
            }
            $redis->setex(
                'sess:' . $token,
                $ttl,
                json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            );
        } catch (\Throwable) {
            // MariaDB už je autorita; výpadek cache nesmí změnit výsledek.
        }
    }

    private function deleteCache(string $token): void
    {
        try {
            $this->cacheClient()?->del('sess:' . $token);
        } catch (\Throwable) {
            // DB revokace/rotace zůstává autoritativní.
        }
    }

    private function cacheClient(): ?RedisClient
    {
        $driver = strtolower((string) $this->config->get('session.driver', 'auto'));
        if (!in_array($driver, ['auto', 'redis', 'db'], true)) {
            throw new \InvalidArgumentException('session.driver musí být auto, redis nebo db.');
        }
        return $driver === 'db' ? null : $this->redis->client();
    }

    private static function isTokenShapeValid(string $token): bool
    {
        return strlen($token) === 64 && ctype_xdigit($token);
    }

    private static function utc(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function nullableUtc(?\DateTimeImmutable $time): ?string
    {
        return $time !== null ? self::utc($time) : null;
    }
}
