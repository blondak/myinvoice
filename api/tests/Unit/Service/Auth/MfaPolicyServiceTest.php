<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Auth;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\MfaPolicyService;
use PHPUnit\Framework\TestCase;

final class MfaPolicyServiceTest extends TestCase
{
    public function testLegacyRequireTotpRemainsTotpOnly(): void
    {
        $policy = new MfaPolicyService(new Config([
            'auth' => [
                'require_mfa'        => null,
                'require_totp'       => true,
                'allowed_mfa_methods' => ['passkey', 'totp'],
            ],
        ]));

        self::assertTrue($policy->isRequired());
        self::assertTrue($policy->usesLegacyTotpPolicy());
        self::assertSame(['totp'], $policy->allowedMethods());
        self::assertFalse($policy->isMethodAllowed('passkey'));
    }

    public function testExplicitMfaPolicyOverridesLegacyFlag(): void
    {
        $policy = new MfaPolicyService(new Config([
            'auth' => [
                'require_mfa'        => false,
                'require_totp'       => true,
                'allowed_mfa_methods' => ['passkey', 'totp'],
            ],
        ]));

        self::assertFalse($policy->isRequired());
        self::assertFalse($policy->usesLegacyTotpPolicy());
        self::assertSame(['passkey', 'totp'], $policy->allowedMethods());
    }

    public function testMethodsAreNormalizedAndDeduplicated(): void
    {
        $policy = new MfaPolicyService(new Config([
            'auth' => [
                'require_mfa'        => true,
                'require_totp'       => false,
                'allowed_mfa_methods' => [' TOTP ', 'passkey', 'totp'],
            ],
        ]));

        self::assertSame(['totp', 'passkey'], $policy->allowedMethods());
        self::assertTrue($policy->satisfiesRequiredMfa('PASSKEY'));
        self::assertFalse($policy->satisfiesRequiredMfa('email_otp'));
    }

    public function testUnknownMethodIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MfaPolicyService(new Config([
            'auth' => [
                'require_mfa'        => true,
                'require_totp'       => false,
                'allowed_mfa_methods' => ['passkey', 'email_otp'],
            ],
        ]));
    }

    public function testEmptyMethodsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MfaPolicyService(new Config([
            'auth' => [
                'require_mfa'        => true,
                'require_totp'       => false,
                'allowed_mfa_methods' => [],
            ],
        ]));
    }
}
