<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PasskeyCredentialRepository;
use PDO;

/**
 * Koordinuje passkey login a unlock jako jeden MariaDB bezpečnostní přechod.
 * Pořadí zámků je user -> session -> ceremony -> credential.
 */
final class PasskeySessionTransitionService
{
    private const CEREMONY_SAVEPOINT = 'webauthn_ceremony_consumed';

    public function __construct(
        private readonly Connection $db,
        private readonly SecurityClock $clock,
        private readonly PasskeyService $passkeys,
        private readonly PasskeyCredentialRepository $credentials,
        private readonly WebAuthnCeremonyStore $ceremonies,
        private readonly SessionManager $sessions,
        private readonly MfaStepUpService $stepUp,
        private readonly MfaStepUpProofStore $proofs,
    ) {}

    /**
     * @param array<string,mixed> $credentialPayload
     * @return array{session:array{token:string,csrf_token:string,expires_at:int,user_id:int,issued_at:\DateTimeImmutable},credential:StoredPasskeyCredential}
     */
    public function completeUnlock(
        string $flowToken,
        array $credentialPayload,
        int $userId,
        string $sessionToken,
    ): array {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $ceremonyConsumed = false;
        try {
            $cutoff = $this->clock->capture($pdo);
            $this->lockActiveUser($pdo, $userId);
            $this->lockSession($pdo, $cutoff, $sessionToken, $userId, true);
            $ceremony = $this->ceremonies->consumeInTransaction(
                $pdo,
                $cutoff,
                $flowToken,
                WebAuthnCeremonyStore::PURPOSE_UNLOCK,
                $userId,
                $sessionToken,
                null,
            );
            $ceremonyConsumed = true;
            $this->saveCeremonyConsumption($pdo);
            $stored = $this->lockCredential($pdo, $credentialPayload, $userId);
            $verified = $this->passkeys->verifyAssertion(
                $credentialPayload,
                $ceremony->options,
                $stored->record,
            );
            if (!$this->credentials->updateAfterAssertionInTransaction(
                $pdo,
                $stored,
                $verified,
                $cutoff,
            )) {
                throw new PasskeyCounterAnomalyException($stored->id);
            }
            $session = $this->sessions->rotateLockedInTransaction(
                $pdo,
                $cutoff,
                $sessionToken,
                $userId,
                'passkey',
                $stored->id,
            );
            $pdo->commit();
        } catch (OneTimeTokenException|PasskeyVerificationException|\DomainException $e) {
            if ($pdo->inTransaction()) {
                $this->commitFailedAttempt(
                    $pdo,
                    $cutoff,
                    $flowToken,
                    $ceremonyConsumed,
                );
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $this->sessions->invalidateCache($sessionToken);
        $this->sessions->invalidateCache($session['token']);
        return ['session' => $session, 'credential' => $stored];
    }

    /**
     * @param array<string,mixed> $credentialPayload
     * @return array{
     *   session:array{token:string,csrf_token:string,expires_at:int,issued_at:\DateTimeImmutable},
     *   credential:StoredPasskeyCredential,
     *   user:array<string,mixed>,
     *   auth_context:SessionAuthContext
     * }
     */
    public function completeLogin(
        string $flowToken,
        array $credentialPayload,
        string $ip,
        string $userAgent,
        int $userId,
    ): array {
        if ($userId < 1) {
            throw new OneTimeTokenException('Neplatné nebo spotřebované WebAuthn flow.');
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $ceremonyConsumed = false;
        try {
            $cutoff = $this->clock->capture($pdo);
            $user = $this->lockActiveUser($pdo, $userId);
            $ceremony = $this->ceremonies->consumeInTransaction(
                $pdo,
                $cutoff,
                $flowToken,
                WebAuthnCeremonyStore::PURPOSE_LOGIN,
                $userId,
                null,
                null,
            );
            $ceremonyConsumed = true;
            $this->saveCeremonyConsumption($pdo);
            $stored = $this->lockCredential($pdo, $credentialPayload, $userId);
            $verified = $this->passkeys->verifyAssertion(
                $credentialPayload,
                $ceremony->options,
                $stored->record,
            );
            if (!$this->credentials->updateAfterAssertionInTransaction(
                $pdo,
                $stored,
                $verified,
                $cutoff,
            )) {
                throw new PasskeyCounterAnomalyException($stored->id);
            }
            $authContext = SessionAuthContext::strong('passkey', $cutoff->utc, $stored->id);
            $session = $this->sessions->createInTransaction(
                $pdo,
                $cutoff,
                $userId,
                $ip,
                $userAgent,
                $authContext,
            );
            $lastLogin = $pdo->prepare(
                'UPDATE users
                    SET last_login_at = FROM_UNIXTIME(?), last_login_ip = ?, last_login_ua = ?
                  WHERE id = ? AND is_active = 1'
            );
            $lastLogin->execute([
                $cutoff->epochSeconds,
                @inet_pton($ip) ?: null,
                mb_substr($userAgent, 0, 255),
                $userId,
            ]);
            if ($lastLogin->rowCount() !== 1) {
                throw new \DomainException('Uživatel už není aktivní.');
            }
            $pdo->commit();
        } catch (OneTimeTokenException|PasskeyVerificationException|\DomainException $e) {
            if ($pdo->inTransaction()) {
                $this->commitFailedAttempt(
                    $pdo,
                    $cutoff,
                    $flowToken,
                    $ceremonyConsumed,
                );
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $this->sessions->invalidateCache($session['token']);
        return [
            'session' => $session,
            'credential' => $stored,
            'user' => $user,
            'auth_context' => $authContext,
        ];
    }

    /**
     * @param array<string,mixed> $credentialPayload
     * @return array{proof_token:string,credential:StoredPasskeyCredential,operation:string}
     */
    public function completeStepUp(
        string $flowToken,
        array $credentialPayload,
        int $userId,
        string $sessionToken,
        string $operation,
    ): array {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $ceremonyConsumed = false;
        try {
            $cutoff = $this->clock->capture($pdo);
            $this->lockActiveUser($pdo, $userId);
            $this->lockSession($pdo, $cutoff, $sessionToken, $userId, false);
            $operation = $this->stepUp->assertAllowed($userId, $operation, 'passkey');
            $ceremony = $this->ceremonies->consumeInTransaction(
                $pdo,
                $cutoff,
                $flowToken,
                WebAuthnCeremonyStore::PURPOSE_STEP_UP,
                $userId,
                $sessionToken,
                $operation,
            );
            $ceremonyConsumed = true;
            $this->saveCeremonyConsumption($pdo);
            $stored = $this->lockCredential($pdo, $credentialPayload, $userId);
            $verified = $this->passkeys->verifyAssertion(
                $credentialPayload,
                $ceremony->options,
                $stored->record,
            );
            if (!$this->credentials->updateAfterAssertionInTransaction(
                $pdo,
                $stored,
                $verified,
                $cutoff,
            )) {
                throw new PasskeyCounterAnomalyException($stored->id);
            }
            $proofToken = $this->proofs->issueInTransaction(
                $pdo,
                $cutoff,
                $userId,
                $sessionToken,
                $operation,
                'passkey',
                $stored->id,
            );
            $pdo->commit();
        } catch (
            OneTimeTokenException
            | PasskeyVerificationException
            | StepUpOperationException
            | \DomainException $e
        ) {
            if ($pdo->inTransaction()) {
                $this->commitFailedAttempt(
                    $pdo,
                    $cutoff,
                    $flowToken,
                    $ceremonyConsumed,
                );
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return [
            'proof_token' => $proofToken,
            'credential' => $stored,
            'operation' => $operation,
        ];
    }

    private function saveCeremonyConsumption(PDO $pdo): void
    {
        $pdo->exec('SAVEPOINT ' . self::CEREMONY_SAVEPOINT);
    }

    private function commitFailedAttempt(
        PDO $pdo,
        SecurityTime $cutoff,
        string $flowToken,
        bool $ceremonyConsumed,
    ): void {
        try {
            if ($ceremonyConsumed) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::CEREMONY_SAVEPOINT);
            } else {
                $this->ceremonies->markAttemptUsedInTransaction($pdo, $cutoff, $flowToken);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function lockActiveUser(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare(
            'SELECT id, email, name, role, locale, password_hash, totp_enabled,
                    session_lock_after_minutes
               FROM users
              WHERE id = ? AND is_active = 1
              FOR UPDATE'
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user === false) {
            throw new PasskeyVerificationException('Ověření passkey selhalo.');
        }
        $user['id'] = (int) $user['id'];
        $user['totp_enabled'] = (int) ($user['totp_enabled'] ?? 0);
        $user['session_lock_after_minutes'] = ($user['session_lock_after_minutes'] ?? null) !== null
            ? (int) $user['session_lock_after_minutes']
            : null;
        return $user;
    }

    private function lockSession(
        PDO $pdo,
        SecurityTime $cutoff,
        string $sessionToken,
        int $userId,
        bool $mustBeLocked,
    ): void {
        $stmt = $pdo->prepare(
            'SELECT id
               FROM sessions
              WHERE id = ? AND user_id = ?
                AND expires_at > FROM_UNIXTIME(?)
                AND replaced_at IS NULL
                AND revoked_at IS NULL'
            . ($mustBeLocked ? ' AND locked_at IS NOT NULL' : ' AND locked_at IS NULL')
            . ' FOR UPDATE'
        );
        $stmt->execute([$sessionToken, $userId, $cutoff->epochSeconds]);
        if ($stmt->fetchColumn() === false) {
            throw new \DomainException('Session už není dostupná.');
        }
    }

    /**
     * @param array<string,mixed> $credentialPayload
     */
    private function lockCredential(
        PDO $pdo,
        array $credentialPayload,
        int $userId,
    ): StoredPasskeyCredential {
        $rawCredentialId = $this->passkeys->credentialId($credentialPayload);
        $stored = $this->credentials->findActiveByCredentialIdForUpdate($pdo, $rawCredentialId);
        if ($stored === null || $stored->userId !== $userId) {
            throw new PasskeyVerificationException('Ověření passkey selhalo.');
        }
        return $stored;
    }
}
