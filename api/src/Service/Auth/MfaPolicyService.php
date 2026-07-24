<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use MyInvoice\Infrastructure\Config\Config;

final class MfaPolicyService
{
    /** @var list<string> */
    private const SUPPORTED_METHODS = ['passkey', 'totp'];

    private readonly bool $required;
    private readonly bool $legacyTotpPolicy;

    /** @var list<string> */
    private readonly array $allowedMethods;

    public function __construct(Config $config)
    {
        $requireMfa = $config->get('auth.require_mfa');
        if ($requireMfa !== null && !is_bool($requireMfa)) {
            throw new \InvalidArgumentException('auth.require_mfa musí být true, false nebo null.');
        }

        $legacyRequireTotp = $config->get('auth.require_totp', false);
        if (!is_bool($legacyRequireTotp)) {
            throw new \InvalidArgumentException('auth.require_totp musí být boolean.');
        }

        $this->legacyTotpPolicy = $requireMfa === null && $legacyRequireTotp;
        $this->required = $requireMfa ?? $legacyRequireTotp;
        $this->allowedMethods = $this->legacyTotpPolicy
            ? ['totp']
            : self::normalizeMethods($config->get('auth.allowed_mfa_methods', self::SUPPORTED_METHODS));
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function usesLegacyTotpPolicy(): bool
    {
        return $this->legacyTotpPolicy;
    }

    /**
     * @return list<string>
     */
    public function allowedMethods(): array
    {
        return $this->allowedMethods;
    }

    public function isMethodAllowed(string $method): bool
    {
        return in_array(strtolower(trim($method)), $this->allowedMethods, true);
    }

    public function satisfiesRequiredMfa(string $method): bool
    {
        return !$this->required || $this->isMethodAllowed($method);
    }

    /**
     * @return list<string>
     */
    private static function normalizeMethods(mixed $methods): array
    {
        if (is_string($methods)) {
            $methods = explode(',', $methods);
        }
        if (!is_array($methods)) {
            throw new \InvalidArgumentException('auth.allowed_mfa_methods musí být pole nebo CSV řetězec.');
        }

        $normalized = [];
        foreach ($methods as $method) {
            if (!is_string($method)) {
                throw new \InvalidArgumentException('MFA metoda musí být řetězec.');
            }
            $method = strtolower(trim($method));
            if ($method === '' || !in_array($method, self::SUPPORTED_METHODS, true)) {
                throw new \InvalidArgumentException('Neznámá nebo prázdná MFA metoda.');
            }
            if (!in_array($method, $normalized, true)) {
                $normalized[] = $method;
            }
        }

        if ($normalized === []) {
            throw new \InvalidArgumentException('Musí být povolená alespoň jedna MFA metoda.');
        }

        return $normalized;
    }
}
