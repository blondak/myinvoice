<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Auth;

use MyInvoice\Action\Auth\PasskeyAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PasskeyCredentialRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\BruteForceGuard;
use MyInvoice\Service\Auth\LoginSessionIssuer;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\MfaStepUpService;
use MyInvoice\Service\Auth\PasskeyService;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\Auth\SessionCookieFactory;
use MyInvoice\Service\Auth\StoredPasskeyCredential;
use MyInvoice\Service\Auth\WebAuthnCeremony;
use MyInvoice\Service\Auth\WebAuthnCeremonyStore;
use MyInvoice\Service\IpMatcher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\TrustPath\EmptyTrustPath;

#[AllowMockObjectsWithoutExpectations]
final class PasskeyActionTest extends TestCase
{
    private Connection&MockObject $db;
    private PasskeyCredentialRepository&MockObject $credentials;
    private PasskeyService&MockObject $passkeys;
    private WebAuthnCeremonyStore&MockObject $ceremonies;
    private MfaStepUpService&MockObject $stepUp;
    private PasswordHasher&MockObject $passwords;
    private MfaPolicyService&MockObject $policy;
    private SessionManager&MockObject $sessions;
    private ActivityLogger&MockObject $logger;
    private IpMatcher&MockObject $ipMatcher;
    private LoginSessionIssuer&MockObject $loginIssuer;
    private ClockInterface&MockObject $clock;
    private SessionCookieFactory&MockObject $sessionCookies;
    private BruteForceGuard&MockObject $bruteForce;

    protected function setUp(): void
    {
        $this->db = $this->createMock(Connection::class);
        $this->credentials = $this->createMock(PasskeyCredentialRepository::class);
        $this->passkeys = $this->createMock(PasskeyService::class);
        $this->ceremonies = $this->createMock(WebAuthnCeremonyStore::class);
        $this->stepUp = $this->createMock(MfaStepUpService::class);
        $this->passwords = $this->createMock(PasswordHasher::class);
        $this->policy = $this->createMock(MfaPolicyService::class);
        $this->sessions = $this->createMock(SessionManager::class);
        $this->logger = $this->createMock(ActivityLogger::class);
        $this->ipMatcher = $this->createMock(IpMatcher::class);
        $this->loginIssuer = $this->createMock(LoginSessionIssuer::class);
        $this->clock = $this->createMock(ClockInterface::class);
        $this->sessionCookies = $this->createMock(SessionCookieFactory::class);
        $this->bruteForce = $this->createMock(BruteForceGuard::class);
    }

    public function testCredentialListContainsOnlyPublicMetadata(): void
    {
        $stored = $this->storedCredential(42, 17, 'Pixel 9');
        $this->credentials->expects(self::once())
            ->method('findAllForUser')
            ->with(17)
            ->willReturn([$stored]);

        $response = $this->action()->credentials(
            $this->sessionRequest('GET', '/api/auth/webauthn/credentials'),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(42, $body['credentials'][0]['id']);
        self::assertSame('Pixel 9', $body['credentials'][0]['label']);
        self::assertSame(['internal'], $body['credentials'][0]['transports']);
        self::assertArrayNotHasKey('credential_id', $body['credentials'][0]);
        self::assertArrayNotHasKey('public_key', $body['credentials'][0]);
        self::assertArrayNotHasKey('aaguid', $body['credentials'][0]);
    }

    public function testBearerCannotUseCredentialManagement(): void
    {
        $this->credentials->expects(self::never())->method('findAllForUser');
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/auth/webauthn/credentials')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 17]);

        $response = $this->action()->credentials(
            $request,
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('session_required', $this->errorCode($response));
    }

    public function testRequiredMfaPreventsRevokingLastAllowedStrongFactor(): void
    {
        $stored = $this->storedCredential(42, 17, 'Jediný klíč');
        $this->credentials->expects(self::once())
            ->method('findActiveForUserById')
            ->with(17, 42)
            ->willReturn($stored);
        $this->credentials->expects(self::once())
            ->method('countActiveForUser')
            ->with(17)
            ->willReturn(1);
        $this->credentials->expects(self::never())->method('revoke');
        $this->stepUp->expects(self::once())
            ->method('consume')
            ->with('proof-token', 17, str_repeat('a', 64), 'passkey.revoke:42');
        $this->policy->expects(self::once())->method('isRequired')->willReturn(true);
        $this->policy->expects(self::exactly(2))->method('isMethodAllowed')
            ->willReturnCallback(static fn (string $method): bool => $method === 'passkey');
        $this->mockFreshUser(['totp_enabled' => 0]);

        $request = $this->sessionRequest('DELETE', '/api/auth/webauthn/credentials/42')
            ->withParsedBody(['step_up_token' => 'proof-token']);
        $response = $this->action()->revoke(
            $request,
            (new ResponseFactory())->createResponse(),
            ['id' => '42'],
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('last_mfa_factor', $this->errorCode($response));
    }

    public function testLoginVerifyIssuesStrongPasskeySessionOnlyAfterCounterUpdate(): void
    {
        $stored = $this->storedCredential(42, 17, 'Pixel 9');
        $payload = ['rawId' => 'synthetic-base64url', 'response' => []];
        $ceremony = new WebAuthnCeremony(
            WebAuthnCeremonyStore::PURPOSE_LOGIN,
            17,
            null,
            random_bytes(32),
            ['challenge' => 'synthetic'],
        );
        $this->ceremonies->expects(self::once())
            ->method('consumeLogin')
            ->with('flow-token')
            ->willReturn($ceremony);
        $this->bruteForce->expects(self::once())
            ->method('isPasskeyLocked')
            ->with(17)
            ->willReturn(false);
        $this->passkeys->expects(self::once())
            ->method('credentialId')
            ->with($payload)
            ->willReturn($stored->record->publicKeyCredentialId);
        $this->credentials->expects(self::once())
            ->method('findActiveByCredentialId')
            ->with($stored->record->publicKeyCredentialId)
            ->willReturn($stored);
        $this->passkeys->expects(self::once())
            ->method('verifyAssertion')
            ->with($payload, $ceremony->options, $stored->record)
            ->willReturn($stored->record);
        $this->credentials->expects(self::once())
            ->method('updateAfterAssertion')
            ->with($stored, $stored->record)
            ->willReturn(true);
        $this->bruteForce->expects(self::once())
            ->method('recordPasskeySuccess')
            ->with(17);
        $now = new \DateTimeImmutable('2026-07-24 12:00:00 UTC');
        $this->clock->expects(self::once())->method('now')->willReturn($now);
        $this->ipMatcher->expects(self::once())
            ->method('clientIpFromRequest')
            ->willReturn('127.0.0.1');
        $this->mockFreshUser();
        $this->loginIssuer->expects(self::once())
            ->method('issue')
            ->willReturnCallback(static function (
                \Psr\Http\Message\ResponseInterface $response,
                array $user,
                string $ip,
                string $userAgent,
                \MyInvoice\Service\Auth\SessionAuthContext $context,
            ): \Psr\Http\Message\ResponseInterface {
                self::assertSame(17, $user['id']);
                self::assertSame('127.0.0.1', $ip);
                self::assertSame('PHPUnit', $userAgent);
                self::assertSame('passkey', $context->authMethod);
                self::assertSame('strong', $context->assuranceLevel);
                self::assertSame(42, $context->authCredentialId);
                return $response->withStatus(204);
            });

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/auth/webauthn/login/verify')
            ->withHeader('User-Agent', 'PHPUnit')
            ->withParsedBody([
                'flow_token' => 'flow-token',
                'credential' => $payload,
            ]);
        $response = $this->action()->loginVerify(
            $request,
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function testLoginVerifyConsumesMalformedAttemptAndRecordsUserFailure(): void
    {
        $ceremony = new WebAuthnCeremony(
            WebAuthnCeremonyStore::PURPOSE_LOGIN,
            17,
            null,
            random_bytes(32),
            ['challenge' => 'synthetic'],
        );
        $this->ceremonies->expects(self::once())
            ->method('consumeLogin')
            ->with('flow-token')
            ->willReturn($ceremony);
        $this->bruteForce->expects(self::once())
            ->method('isPasskeyLocked')
            ->with(17)
            ->willReturn(false);
        $this->bruteForce->expects(self::once())
            ->method('recordPasskeyFailure')
            ->with(17);
        $this->loginIssuer->expects(self::never())->method('issue');
        $this->ipMatcher->method('clientIpFromRequest')->willReturn('127.0.0.1');

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/auth/webauthn/login/verify')
            ->withHeader('User-Agent', 'PHPUnit')
            ->withParsedBody(['flow_token' => 'flow-token']);
        $response = $this->action()->loginVerify(
            $request,
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('passkey_verification_failed', $this->errorCode($response));
    }

    public function testSetupRegistrationRevokesSetupSessionsAndReturnsRotatedCookie(): void
    {
        $stored = $this->storedCredential(42, 17, 'Pixel 9');
        $payload = ['rawId' => 'synthetic-base64url', 'response' => []];
        $ceremony = new WebAuthnCeremony(
            WebAuthnCeremonyStore::PURPOSE_REGISTER,
            17,
            null,
            random_bytes(32),
            ['challenge' => 'synthetic'],
        );
        $this->policy->expects(self::once())->method('isMethodAllowed')->with('passkey')->willReturn(true);
        $this->ceremonies->expects(self::once())
            ->method('consume')
            ->with('flow-token', WebAuthnCeremonyStore::PURPOSE_REGISTER, 17, str_repeat('a', 64), null)
            ->willReturn($ceremony);
        $this->passkeys->expects(self::once())
            ->method('verifyRegistration')
            ->with($payload, $ceremony->options)
            ->willReturn($stored->record);
        $this->credentials->expects(self::once())
            ->method('save')
            ->with(17, $stored->record, 'Pixel 9')
            ->willReturn(42);
        $this->credentials->expects(self::once())
            ->method('findActiveForUserById')
            ->with(17, 42)
            ->willReturn($stored);
        $this->mockFreshUser();
        $now = new \DateTimeImmutable('2026-07-24 12:00:00 UTC');
        $this->clock->expects(self::once())->method('now')->willReturn($now);
        $this->ipMatcher->method('clientIpFromRequest')->willReturn('127.0.0.1');
        $this->sessions->expects(self::once())
            ->method('completeSetup')
            ->willReturnCallback(static function (
                int $userId,
                string $token,
                string $ip,
                string $userAgent,
                \MyInvoice\Service\Auth\SessionAuthContext $context,
            ): array {
                self::assertSame(17, $userId);
                self::assertSame(str_repeat('a', 64), $token);
                self::assertSame('127.0.0.1', $ip);
                self::assertSame('PHPUnit', $userAgent);
                self::assertSame('strong', $context->assuranceLevel);
                self::assertSame('passkey', $context->authMethod);
                self::assertSame(42, $context->authCredentialId);
                return [
                    'token' => str_repeat('c', 64),
                    'csrf_token' => str_repeat('d', 64),
                    'expires_at' => 1_800_000_000,
                ];
            });
        $this->sessionCookies->expects(self::once())
            ->method('create')
            ->with(str_repeat('c', 64), 1_800_000_000)
            ->willReturn('__Host-myinvoice_session=rotated');

        $request = $this->sessionRequest('POST', '/api/auth/webauthn/register/verify')
            ->withHeader('User-Agent', 'PHPUnit')
            ->withAttribute(AuthMiddleware::ATTR_SESSION, ['assurance_level' => 'setup'])
            ->withParsedBody([
                'flow_token' => 'flow-token',
                'credential' => $payload,
                'label' => 'Pixel 9',
            ]);
        $response = $this->action()->registerVerify(
            $request,
            (new ResponseFactory())->createResponse(),
        );
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(str_repeat('d', 64), $body['csrf_token']);
        self::assertSame('active', $body['session_state']);
        self::assertFalse($body['must_setup_mfa']);
        self::assertSame('__Host-myinvoice_session=rotated', $response->getHeaderLine('Set-Cookie'));
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function mockFreshUser(array $overrides = []): void
    {
        $row = array_replace([
            'id' => 17,
            'email' => 'synthetic@example.invalid',
            'name' => 'Synthetic User',
            'password_hash' => 'synthetic-hash',
            'totp_enabled' => 0,
        ], $overrides);
        $statement = $this->createMock(\PDOStatement::class);
        $statement->expects(self::once())->method('execute')->with([17])->willReturn(true);
        $statement->expects(self::once())->method('fetch')->with(\PDO::FETCH_ASSOC)->willReturn($row);
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects(self::once())->method('prepare')->willReturn($statement);
        $this->db->expects(self::once())->method('pdo')->willReturn($pdo);
    }

    private function action(): PasskeyAction
    {
        return new PasskeyAction(
            $this->db,
            $this->credentials,
            $this->passkeys,
            $this->ceremonies,
            $this->passwords,
            $this->policy,
            $this->sessions,
            $this->logger,
            $this->ipMatcher,
            $this->loginIssuer,
            $this->clock,
            $this->stepUp,
            $this->sessionCookies,
            $this->bruteForce,
        );
    }

    private function sessionRequest(string $method, string $path): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 17])
            ->withAttribute(AuthMiddleware::ATTR_TOKEN, str_repeat('a', 64))
            ->withAttribute(AuthMiddleware::ATTR_SESSION, ['assurance_level' => 'strong']);
    }

    private function storedCredential(int $id, int $userId, string $label): StoredPasskeyCredential
    {
        $record = CredentialRecord::create(
            random_bytes(32),
            PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            ['internal'],
            'none',
            EmptyTrustPath::create(),
            Uuid::fromBinary(str_repeat("\0", 16)),
            random_bytes(77),
            random_bytes(32),
            0,
            null,
            true,
            false,
            true,
        );

        return new StoredPasskeyCredential(
            $id,
            $userId,
            $label,
            $record,
            '2026-07-24 12:00:00.000000',
            null,
            null,
        );
    }

    private function errorCode(\Psr\Http\Message\ResponseInterface $response): ?string
    {
        $body = json_decode((string) $response->getBody(), true);
        return $body['error']['code'] ?? null;
    }
}
