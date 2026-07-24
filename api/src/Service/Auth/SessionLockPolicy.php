<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use MyInvoice\Infrastructure\Config\Config;

final class SessionLockPolicy
{
    public const MIN_TIMEOUT_MINUTES = 1;
    public const MAX_TIMEOUT_MINUTES = 1440;

    private readonly int $adminTimeoutMinutes;

    public function __construct(Config $config)
    {
        $timeout = $config->get('session.lock_after_minutes', 0);
        if (!is_int($timeout) || $timeout < 0 || $timeout > self::MAX_TIMEOUT_MINUTES) {
            throw new \InvalidArgumentException(sprintf(
                'session.lock_after_minutes musí být 0 až %d.',
                self::MAX_TIMEOUT_MINUTES,
            ));
        }

        $this->adminTimeoutMinutes = $timeout;
    }

    public function isEnabled(): bool
    {
        return $this->adminTimeoutMinutes > 0;
    }

    public function timeoutMinutes(): int
    {
        return $this->adminTimeoutMinutes;
    }

    public function timeoutSeconds(): int
    {
        return $this->adminTimeoutMinutes * 60;
    }

    public function maximumUserTimeoutMinutes(): int
    {
        return $this->adminTimeoutMinutes > 0
            ? $this->adminTimeoutMinutes
            : self::MAX_TIMEOUT_MINUTES;
    }

    public function effectiveTimeoutMinutes(?int $userTimeoutMinutes): int
    {
        if ($userTimeoutMinutes === null) {
            return $this->adminTimeoutMinutes;
        }
        $this->assertStoredUserTimeout($userTimeoutMinutes);

        return $this->adminTimeoutMinutes > 0
            ? min($userTimeoutMinutes, $this->adminTimeoutMinutes)
            : $userTimeoutMinutes;
    }

    public function effectiveTimeoutSeconds(?int $userTimeoutMinutes): int
    {
        return $this->effectiveTimeoutMinutes($userTimeoutMinutes) * 60;
    }

    public function assertUserTimeoutAllowed(?int $userTimeoutMinutes): void
    {
        if ($userTimeoutMinutes === null) {
            return;
        }
        $this->assertStoredUserTimeout($userTimeoutMinutes);
        $maximum = $this->maximumUserTimeoutMinutes();
        if ($userTimeoutMinutes > $maximum) {
            throw new \InvalidArgumentException(sprintf(
                'Vlastní timeout zámku musí být nejvýše %d minut.',
                $maximum,
            ));
        }
    }

    private function assertStoredUserTimeout(int $userTimeoutMinutes): void
    {
        if ($userTimeoutMinutes < self::MIN_TIMEOUT_MINUTES
            || $userTimeoutMinutes > self::MAX_TIMEOUT_MINUTES
        ) {
            throw new \InvalidArgumentException(sprintf(
                'Vlastní timeout zámku musí být %d až %d minut.',
                self::MIN_TIMEOUT_MINUTES,
                self::MAX_TIMEOUT_MINUTES,
            ));
        }
    }
}
