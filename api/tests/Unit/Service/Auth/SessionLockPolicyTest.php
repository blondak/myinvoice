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
    }

    public function testZeroDisablesAutomaticLock(): void
    {
        $policy = new SessionLockPolicy(new Config(['session' => ['lock_after_minutes' => 0]]));

        self::assertFalse($policy->isEnabled());
        self::assertSame(0, $policy->timeoutSeconds());
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
}
