<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Auth;

use MyInvoice\Infrastructure\Cache\RedisFactory;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SessionAuthContext;
use MyInvoice\Service\Auth\DatabaseSecurityClock;
use MyInvoice\Service\Auth\SessionLockPolicy;
use MyInvoice\Service\Auth\SessionLockService;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\Auth\PsrSecurityClock;
use MyInvoice\Service\Auth\WebAuthnCeremonyStore;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[Group('integration')]
final class SessionSecurityTest extends TestCase
{
    private Connection $db;
    private Config $config;
    private SessionTestClock $clock;
    private SessionManager $sessions;
    private SessionLockService $lock;
    private int $userId = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 5);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing — skip live-DB test.');
        }

        try {
            $loaded = Config::load($rootDir)->all();
            $loaded['redis']['enabled'] = false;
            $loaded['session']['lifetime_days'] = 30;
            $loaded['session']['lock_after_minutes'] = 15;
            $this->config = new Config($loaded);
            $this->db = new Connection($this->config);
            if (!$this->db->hasColumn('sessions', 'session_family_id')
                || !$this->db->hasColumn('sessions', 'last_user_activity_at')
            ) {
                $this->markTestSkipped('WebAuthn migration 0145 is not applied.');
            }

            $stmt = $this->db->pdo()->prepare(
                'INSERT INTO users (email, password_hash, name, role, locale)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                'session-test-' . bin2hex(random_bytes(8)) . '@example.invalid',
                password_hash('Synthetic-test-password-42', PASSWORD_BCRYPT),
                'Synthetic Session Test',
                'admin',
                'cs',
            ]);
            $this->userId = (int) $this->db->pdo()->lastInsertId();
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB unavailable: ' . $e->getMessage());
        }

        $this->clock = new SessionTestClock(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->sessions = new SessionManager(
            $this->db,
            new RedisFactory($this->config),
            $this->config,
            $this->clock,
            new PsrSecurityClock($this->clock),
        );
        $this->lock = new SessionLockService(
            $this->db,
            $this->sessions,
            new SessionLockPolicy($this->config),
            new PsrSecurityClock($this->clock),
            new WebAuthnCeremonyStore($this->db, new PsrSecurityClock($this->clock)),
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->userId > 0) {
            $this->db->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$this->userId]);
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testCreatePersistsStrongAuthAndFixedSessionLineage(): void
    {
        $context = SessionAuthContext::strong('password_passkey', $this->clock->now(), 42);
        $created = $this->sessions->create($this->userId, '127.0.0.1', 'PHPUnit', $context);
        $loaded = $this->sessions->load($created['token']);

        self::assertNotNull($loaded);
        self::assertSame($this->userId, $loaded['user_id']);
        self::assertSame($created['csrf_token'], $loaded['csrf_token']);
        self::assertSame('password_passkey', $loaded['auth_method']);
        self::assertSame('strong', $loaded['assurance_level']);
        self::assertSame(42, $loaded['auth_credential_id']);
        self::assertNotNull($loaded['mfa_verified_at']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $loaded['session_family_id']);
        self::assertSame(1, $loaded['generation']);
        self::assertSame($created['expires_at'], $loaded['expires_at']);
    }

    public function testNetworkTouchDoesNotMoveUserActivityOrAbsoluteExpiry(): void
    {
        $created = $this->sessions->create($this->userId, '127.0.0.1', 'PHPUnit');
        $before = $this->sessions->load($created['token']);
        self::assertNotNull($before);

        $this->clock->advance('+10 minutes');
        $this->sessions->touch($created['token']);
        $after = $this->sessions->load($created['token']);

        self::assertNotNull($after);
        self::assertSame($before['last_user_activity_at'], $after['last_user_activity_at']);
        self::assertSame($before['expires_at'], $after['expires_at']);
    }

    public function testActivityMovesDeadlineAndIdleBoundaryLocksSession(): void
    {
        $created = $this->sessions->create($this->userId, '127.0.0.1', 'PHPUnit');
        $this->clock->advance('+10 minutes');
        $activity = $this->lock->recordActivity($created['token']);

        self::assertFalse($activity->locked);
        self::assertSame(
            $this->clock->now()->modify('+15 minutes')->format('U.u'),
            $activity->idleExpiresAt(new SessionLockPolicy($this->config))?->format('U.u'),
        );

        $this->clock->advance('+14 minutes 59 seconds');
        self::assertFalse($this->lock->evaluate($created['token'])->locked);

        $this->clock->advance('+1 second');
        $locked = $this->lock->evaluate($created['token']);
        self::assertTrue($locked->locked);
        self::assertTrue($locked->transitioned);
        self::assertSame('idle', $locked->reason);

        $stillLocked = $this->lock->recordActivity($created['token']);
        self::assertTrue($stillLocked->locked);
        self::assertFalse($stillLocked->transitioned);
    }

    public function testManualLockWorksWhenAutomaticTimeoutIsDisabled(): void
    {
        $created = $this->sessions->create($this->userId, '127.0.0.1', 'PHPUnit');
        $disabledPolicy = new SessionLockPolicy(new Config(['session' => ['lock_after_minutes' => 0]]));
        $lock = new SessionLockService(
            $this->db,
            $this->sessions,
            $disabledPolicy,
            new PsrSecurityClock($this->clock),
            new WebAuthnCeremonyStore($this->db, new PsrSecurityClock($this->clock)),
        );

        self::assertFalse($lock->evaluate($created['token'])->locked);
        $result = $lock->lockManually($created['token']);
        self::assertTrue($result->locked);
        self::assertTrue($result->transitioned);
        self::assertSame('manual', $result->reason);
    }

    public function testDestroyRevokesOnlyPresentedSessionFamily(): void
    {
        $first = $this->sessions->create($this->userId, '127.0.0.1', 'PHPUnit');
        $second = $this->sessions->create($this->userId, '127.0.0.1', 'PHPUnit');

        $this->sessions->destroy($first['token']);

        self::assertNull($this->sessions->load($first['token']));
        self::assertNotNull($this->sessions->load($second['token']));
    }

    public function testDestroyAllCanPreserveCurrentSessionFamily(): void
    {
        $current = $this->sessions->create($this->userId, '127.0.0.1', 'PHPUnit');
        $otherA = $this->sessions->create($this->userId, '127.0.0.1', 'PHPUnit');
        $otherB = $this->sessions->create($this->userId, '127.0.0.1', 'PHPUnit');

        self::assertSame(2, $this->sessions->destroyAllForUser($this->userId, $current['token']));
        self::assertNotNull($this->sessions->load($current['token']));
        self::assertNull($this->sessions->load($otherA['token']));
        self::assertNull($this->sessions->load($otherB['token']));
    }

    public function testUnlockRotationPreservesAbsoluteExpiryAndAuthorizationContext(): void
    {
        $credentialId = $this->createCredentialRow();
        $context = SessionAuthContext::strong('passkey', $this->clock->now(), $credentialId);
        $created = $this->sessions->create($this->userId, '127.0.0.1', 'PHPUnit', $context);
        $before = $this->sessions->load($created['token']);
        self::assertNotNull($before);
        self::assertTrue($this->lock->lockManually($created['token'])->locked);

        $this->clock->advance('+5 minutes');
        $rotated = $this->sessions->rotateLocked($created['token'], 'passkey', $credentialId);
        $after = $this->sessions->load($rotated['token']);

        self::assertNull($this->sessions->load($created['token']));
        self::assertNotNull($after);
        self::assertSame($before['expires_at'], $after['expires_at']);
        self::assertSame($before['created_at'], $after['created_at']);
        self::assertSame($before['session_family_id'], $after['session_family_id']);
        self::assertSame(2, $after['generation']);
        self::assertSame('passkey', $after['auth_method']);
        self::assertSame('strong', $after['assurance_level']);
        self::assertSame($before['mfa_verified_at'], $after['mfa_verified_at']);
        self::assertSame($credentialId, $after['auth_credential_id']);
        self::assertSame('passkey', $after['last_unlock_method']);
        self::assertNotNull($after['last_unlock_at']);
        self::assertNull($after['locked_at']);
        self::assertSame($rotated['csrf_token'], $after['csrf_token']);

        $this->expectException(\DomainException::class);
        $this->sessions->rotateLocked($created['token'], 'passkey', $credentialId);
    }

    public function testCompletingSetupRevokesEverySetupSessionAndCreatesNewStrongFamily(): void
    {
        $first = $this->sessions->create(
            $this->userId,
            '127.0.0.1',
            'First setup',
            SessionAuthContext::setup('password'),
        );
        $second = $this->sessions->create(
            $this->userId,
            '127.0.0.2',
            'Second setup',
            SessionAuthContext::setup('password'),
        );
        $existingStrong = $this->sessions->create(
            $this->userId,
            '127.0.0.3',
            'Existing strong',
            SessionAuthContext::strong('totp', $this->clock->now()),
        );

        $this->clock->advance('+5 minutes');
        $completed = $this->sessions->completeSetup(
            $this->userId,
            $first['token'],
            '127.0.0.1',
            'Completed setup',
            SessionAuthContext::strong('passkey', $this->clock->now(), 42),
        );
        $loaded = $this->sessions->load($completed['token']);

        self::assertNull($this->sessions->load($first['token']));
        self::assertNull($this->sessions->load($second['token']));
        self::assertNotNull($this->sessions->load($existingStrong['token']));
        self::assertNotNull($loaded);
        self::assertSame('strong', $loaded['assurance_level']);
        self::assertSame('passkey', $loaded['auth_method']);
        self::assertSame(42, $loaded['auth_credential_id']);
        self::assertSame(1, $loaded['generation']);
        self::assertNotSame(
            $this->sessions->load($existingStrong['token'])['session_family_id'],
            $loaded['session_family_id'],
        );
        self::assertSame($completed['csrf_token'], $loaded['csrf_token']);

        $this->expectException(\DomainException::class);
        $this->sessions->completeSetup(
            $this->userId,
            $second['token'],
            '127.0.0.2',
            'Replay',
            SessionAuthContext::strong('passkey', $this->clock->now(), 42),
        );
    }

    public function testSetupCompletionKeepsExactLifetimeWithNonUtcDatabaseTimezone(): void
    {
        $this->db->pdo()->exec("SET time_zone = '+09:00'");
        $setup = $this->sessions->create(
            $this->userId,
            '127.0.0.1',
            'Timezone setup',
            SessionAuthContext::setup('password'),
        );

        $this->clock->advance('+5 minutes');
        $completed = $this->sessions->completeSetup(
            $this->userId,
            $setup['token'],
            '127.0.0.1',
            'Timezone completion',
            SessionAuthContext::strong('passkey', $this->clock->now(), 42),
        );
        $loaded = $this->sessions->load($completed['token']);

        self::assertNotNull($loaded);
        self::assertSame($completed['issued_at']->getTimestamp(), $loaded['created_at']);
        self::assertSame(30 * 86400, $loaded['expires_at'] - $loaded['created_at']);
        self::assertSame($completed['expires_at'], $loaded['expires_at']);
    }

    public function testDatabaseSecurityClockReturnsMatchingUtcAndEpochOutsideUtcSession(): void
    {
        $this->db->pdo()->exec("SET time_zone = '-07:00'");

        $captured = (new DatabaseSecurityClock())->capture($this->db->pdo());

        self::assertSame('UTC', $captured->utc->getTimezone()->getName());
        self::assertSame($captured->utc->getTimestamp(), $captured->epochSeconds);
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/',
            $captured->utcSql,
        );
    }

    private function createCredentialRow(): int
    {
        $handle = random_bytes(32);
        $this->db->pdo()->prepare('UPDATE users SET webauthn_user_handle = ? WHERE id = ?')
            ->execute([$handle, $this->userId]);
        $credentialId = random_bytes(32);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO webauthn_credentials
                (user_id, credential_id, credential_id_hash, public_key, transports_json,
                 aaguid, label, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))'
        );
        $stmt->execute([
            $this->userId,
            $credentialId,
            hash('sha256', $credentialId, true),
            random_bytes(77),
            '[]',
            str_repeat("\0", 16),
            'Synthetic session key',
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }
}

final class SessionTestClock implements ClockInterface
{
    public function __construct(private \DateTimeImmutable $current) {}

    public function now(): \DateTimeImmutable
    {
        return $this->current;
    }

    public function advance(string $modify): void
    {
        $this->current = $this->current->modify($modify);
    }
}
