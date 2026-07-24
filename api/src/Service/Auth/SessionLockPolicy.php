<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use MyInvoice\Infrastructure\Config\Config;

final class SessionLockPolicy
{
    public const MAX_TIMEOUT_MINUTES = 1440;

    private readonly int $timeoutMinutes;

    public function __construct(Config $config)
    {
        $timeout = $config->get('session.lock_after_minutes', 0);
        if (!is_int($timeout) || $timeout < 0 || $timeout > self::MAX_TIMEOUT_MINUTES) {
            throw new \InvalidArgumentException(sprintf(
                'session.lock_after_minutes musí být 0 až %d.',
                self::MAX_TIMEOUT_MINUTES,
            ));
        }

        $this->timeoutMinutes = $timeout;
    }

    public function isEnabled(): bool
    {
        return $this->timeoutMinutes > 0;
    }

    public function timeoutMinutes(): int
    {
        return $this->timeoutMinutes;
    }

    public function timeoutSeconds(): int
    {
        return $this->timeoutMinutes * 60;
    }
}
