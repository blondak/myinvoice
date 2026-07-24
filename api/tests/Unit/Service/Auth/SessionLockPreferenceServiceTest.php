<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Auth;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SessionLockPolicy;
use MyInvoice\Service\Auth\SessionLockPreferenceService;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class SessionLockPreferenceServiceTest extends TestCase
{
    public function testGetDescribesUserPreferenceAndAdminLimit(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects(self::once())->method('execute')->with([17]);
        $statement->expects(self::once())->method('fetch')->with(PDO::FETCH_ASSOC)->willReturn([
            'session_lock_after_minutes' => '5',
        ]);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('prepare')->willReturn($statement);
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('pdo')->willReturn($pdo);
        $policy = new SessionLockPolicy(new Config([
            'session' => ['lock_after_minutes' => 15],
        ]));

        $preference = (new SessionLockPreferenceService($db, $policy))->get(17);

        self::assertSame([
            'user_lock_after_minutes' => 5,
            'admin_lock_after_minutes' => 15,
            'maximum_lock_after_minutes' => 15,
            'effective_lock_after_minutes' => 5,
        ], $preference);
    }

    public function testUpdateRejectsTimeoutAboveAdminLimitBeforeDatabaseWrite(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('pdo');
        $policy = new SessionLockPolicy(new Config([
            'session' => ['lock_after_minutes' => 15],
        ]));

        $this->expectException(\InvalidArgumentException::class);
        (new SessionLockPreferenceService($db, $policy))->update(17, 30);
    }
}
