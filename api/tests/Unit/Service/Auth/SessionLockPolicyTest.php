<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Auth;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\SessionLockPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SessionLockPolicyTest extends TestCase
{
    public function testDefaultTimeout(): void
    {
        $policy = new SessionLockPolicy(new Config([]));

        self::assertFalse($policy->isEnabled());
        self::assertSame(0, $policy->timeoutMinutes());
        self::assertSame(0, $policy->timeoutSeconds());
        self::assertSame(1440, $policy->maximumUserTimeoutMinutes());
        self::assertSame(0, $policy->effectiveTimeoutMinutes(null));
        self::assertSame(15, $policy->effectiveTimeoutMinutes(15));
    }

    public function testZeroDisablesAutomaticLock(): void
    {
        $policy = new SessionLockPolicy(new Config(['session' => ['lock_after_minutes' => 0]]));

        self::assertFalse($policy->isEnabled());
        self::assertSame(0, $policy->timeoutSeconds());
    }

    public function testPositiveAdminTimeoutIsDefaultAndMaximumForUserPreference(): void
    {
        $policy = new SessionLockPolicy(new Config(['session' => ['lock_after_minutes' => 30]]));

        self::assertSame(30, $policy->maximumUserTimeoutMinutes());
        self::assertSame(30, $policy->effectiveTimeoutMinutes(null));
        self::assertSame(5, $policy->effectiveTimeoutMinutes(5));
        self::assertSame(30, $policy->effectiveTimeoutMinutes(60));
        $policy->assertUserTimeoutAllowed(30);

        $this->expectException(\InvalidArgumentException::class);
        $policy->assertUserTimeoutAllowed(31);
    }

    #[DataProvider('invalidUserTimeoutProvider')]
    public function testInvalidUserTimeoutIsRejected(int $timeout): void
    {
        $policy = new SessionLockPolicy(new Config([]));

        $this->expectException(\InvalidArgumentException::class);
        $policy->assertUserTimeoutAllowed($timeout);
    }

    #[DataProvider('invalidTimeoutProvider')]
    public function testInvalidTimeoutIsRejected(mixed $timeout): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SessionLockPolicy(new Config(['session' => ['lock_after_minutes' => $timeout]]));
    }

    /**
     * @return iterable<string,array{mixed}>
     */
    public static function invalidTimeoutProvider(): iterable
    {
        yield 'negative' => [-1];
        yield 'too high' => [SessionLockPolicy::MAX_TIMEOUT_MINUTES + 1];
        yield 'string' => ['15'];
        yield 'float' => [15.5];
        yield 'null' => [null];
    }

    /**
     * @return iterable<string,array{int}>
     */
    public static function invalidUserTimeoutProvider(): iterable
    {
        yield 'zero is represented by inherited disabled policy' => [0];
        yield 'negative' => [-1];
        yield 'too high' => [SessionLockPolicy::MAX_TIMEOUT_MINUTES + 1];
    }
}
