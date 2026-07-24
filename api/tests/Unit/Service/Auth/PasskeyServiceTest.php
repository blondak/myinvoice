<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Auth;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\PasskeyService;
use MyInvoice\Service\Auth\PasskeyVerificationException;
use MyInvoice\Service\Auth\WebAuthnConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\TrustPath\EmptyTrustPath;

final class PasskeyServiceTest extends TestCase
{
    private PasskeyService $service;

    protected function setUp(): void
    {
        $config = new WebAuthnConfig(new Config(['app' => ['url' => 'https://invoice.example.cz']]));
        $this->service = new PasskeyService($config);
    }

    public function testRegistrationOptionsEnforceDiscoverableCredentialAndUserVerification(): void
    {
        $handle = random_bytes(32);
        $existing = $this->credential($handle);
        $challenge = random_bytes(32);

        $options = $this->service->registrationOptions(
            'user@example.invalid',
            'Synthetic User',
            $handle,
            [$existing],
            $challenge,
        );

        self::assertSame('MyInvoice.cz', $options['rp']['name']);
        self::assertSame('invoice.example.cz', $options['rp']['id']);
        self::assertSame('user@example.invalid', $options['user']['name']);
        self::assertSame('Synthetic User', $options['user']['displayName']);
        self::assertSame(self::base64url($handle), $options['user']['id']);
        self::assertSame(self::base64url($challenge), $options['challenge']);
        self::assertSame(
            [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]],
            $options['pubKeyCredParams'],
        );
        self::assertSame('required', $options['authenticatorSelection']['residentKey']);
        self::assertTrue($options['authenticatorSelection']['requireResidentKey']);
        self::assertSame('required', $options['authenticatorSelection']['userVerification']);
        self::assertSame('none', $options['attestation']);
        self::assertSame(self::base64url($existing->publicKeyCredentialId), $options['excludeCredentials'][0]['id']);
    }

    public function testAssertionOptionsAreRestrictedToKnownCredentialsAndRequireVerification(): void
    {
        $handle = random_bytes(32);
        $credential = $this->credential($handle);
        $challenge = random_bytes(32);

        $options = $this->service->assertionOptions([$credential], $challenge);

        self::assertSame('invoice.example.cz', $options['rpId']);
        self::assertSame('required', $options['userVerification']);
        self::assertSame(self::base64url($challenge), $options['challenge']);
        self::assertSame(self::base64url($credential->publicKeyCredentialId), $options['allowCredentials'][0]['id']);
        self::assertSame(['internal', 'hybrid'], $options['allowCredentials'][0]['transports']);
    }

    public function testAssertionOptionsRejectEmptyCredentialList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->assertionOptions([], random_bytes(32));
    }

    public function testMalformedRegistrationPayloadIsMappedToSanitizedError(): void
    {
        $options = $this->service->registrationOptions(
            'user@example.invalid',
            'Synthetic User',
            random_bytes(32),
            [],
            random_bytes(32),
        );

        try {
            $this->service->verifyRegistration(
                ['rawId' => 'secret-credential-material', 'response' => ['attestationObject' => 'secret']],
                $options,
            );
            self::fail('Malformed payload must fail.');
        } catch (PasskeyVerificationException $e) {
            self::assertSame('Registraci passkey se nepodařilo ověřit.', $e->getMessage());
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    public function testMalformedAssertionPayloadIsMappedToSanitizedError(): void
    {
        $credential = $this->credential(random_bytes(32));
        $options = $this->service->assertionOptions([$credential], random_bytes(32));

        try {
            $this->service->verifyAssertion(
                ['rawId' => 'secret-credential-material', 'response' => ['signature' => 'secret']],
                $options,
                $credential,
            );
            self::fail('Malformed payload must fail.');
        } catch (PasskeyVerificationException $e) {
            self::assertSame('Ověření passkey selhalo.', $e->getMessage());
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    public function testCredentialIdDecodesUnpaddedBase64Url(): void
    {
        $rawId = random_bytes(32);
        self::assertSame($rawId, $this->service->credentialId([
            'rawId' => self::base64url($rawId),
        ]));
    }

    public function testCredentialIdRejectsMalformedOrOversizedValues(): void
    {
        foreach ([
            [],
            ['rawId' => '*not-base64url*'],
            ['rawId' => self::base64url(str_repeat('x', 1025))],
        ] as $payload) {
            try {
                $this->service->credentialId($payload);
                self::fail('Neplatné rawId musí být odmítnuto.');
            } catch (PasskeyVerificationException $e) {
                self::assertSame('Ověření passkey selhalo.', $e->getMessage());
                self::assertNull($e->getPrevious());
            }
        }
    }

    private function credential(string $handle): CredentialRecord
    {
        return CredentialRecord::create(
            random_bytes(32),
            PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            ['internal', 'hybrid'],
            'none',
            EmptyTrustPath::create(),
            Uuid::fromBinary(str_repeat("\0", 16)),
            random_bytes(77),
            $handle,
            0,
            null,
            true,
            false,
            true,
        );
    }

    private static function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
