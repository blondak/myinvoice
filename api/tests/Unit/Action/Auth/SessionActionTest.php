<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Auth;

use MyInvoice\Action\Auth\SessionAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PasskeyCredentialRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\BruteForceGuard;
use MyInvoice\Service\Auth\PasskeyService;
use MyInvoice\Service\Auth\SessionLockPolicy;
use MyInvoice\Service\Auth\SessionLockResult;
use MyInvoice\Service\Auth\SessionLockService;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\Auth\SessionCookieFactory;
use MyInvoice\Service\Auth\WebAuthnCeremony;
use MyInvoice\Service\Auth\WebAuthnCeremonyStore;
use MyInvoice\Service\IpMatcher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

#[AllowMockObjectsWithoutExpectations]
final class SessionActionTest extends TestCase
{
    public function testStatusReturnsMinimalAuthoritativeContract(): void
    {
        $locks = $this->createMock(SessionLockService::class);
        $locks->expects(self::once())
            ->method('evaluate')
            ->with(str_repeat('a', 64))
            ->willReturn(SessionLockResult::active(
                new \DateTimeImmutable('2026-07-24 12:00:00 UTC'),
            ));
        $credentials = $this->createMock(PasskeyCredentialRepository::class);
        $credentials->expects(self::once())->method('countActiveForUser')->with(17)->willReturn(2);
        $clock = $this->createMock(ClockInterface::class);
        $clock->expects(self::once())
            ->method('now')
            ->willReturn(new \DateTimeImmutable('2026-07-24 12:05:00 UTC'));
        $action = new SessionAction(
            $this->createMock(SessionManager::class),
            $locks,
            new SessionLockPolicy(new Config(['session' => ['lock_after_minutes' => 15]])),
            $credentials,
            $this->createMock(PasskeyService::class),
            $this->createMock(WebAuthnCeremonyStore::class),
            $this->createMock(ActivityLogger::class),
            $this->createMock(IpMatcher::class),
            $clock,
            $this->createMock(BruteForceGuard::class),
            $this->createMock(SessionCookieFactory::class),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/auth/session/status')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_TOKEN, str_repeat('a', 64))
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 17, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_SESSION, [
                'csrf_token' => str_repeat('b', 64),
                'assurance_level' => 'strong',
            ]);

        $response = $action->status($request, (new ResponseFactory())->createResponse());
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame([
            'session_state' => 'active',
            'csrf_token' => str_repeat('b', 64),
            'server_time' => '2026-07-24T12:05:00.000000Z',
            'idle_expires_at' => '2026-07-24T12:15:00.000000Z',
            'lock_after_minutes' => 15,
            'unlock_methods' => ['passkey'],
            'user' => [
                'id' => 17,
                'email' => '',
                'name' => '',
                'role' => 'admin',
                'locale' => 'cs',
                'totp_enabled' => false,
            ],
        ], $body);
        self::assertArrayNotHasKey('role', $body);
        self::assertArrayNotHasKey('assurance_level', $body);
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testMalformedUnlockConsumesCeremonyAndRecordsFailure(): void
    {
        $ceremonies = $this->createMock(WebAuthnCeremonyStore::class);
        $ceremonies->expects(self::once())
            ->method('consume')
            ->with(
                'flow-token',
                WebAuthnCeremonyStore::PURPOSE_UNLOCK,
                17,
                str_repeat('a', 64),
                null,
            )
            ->willReturn(new WebAuthnCeremony(
                WebAuthnCeremonyStore::PURPOSE_UNLOCK,
                17,
                null,
                random_bytes(32),
                ['challenge' => 'synthetic'],
            ));
        $bruteForce = $this->createMock(BruteForceGuard::class);
        $bruteForce->expects(self::once())->method('isPasskeyLocked')->with(17)->willReturn(false);
        $bruteForce->expects(self::once())->method('recordPasskeyFailure')->with(17);
        $logger = $this->createMock(ActivityLogger::class);
        $logger->expects(self::once())
            ->method('log')
            ->with(
                'auth.session_unlock_failed',
                17,
                'user',
                17,
                null,
                '127.0.0.1',
                'PHPUnit',
            );
        $ipMatcher = $this->createMock(IpMatcher::class);
        $ipMatcher->method('clientIpFromRequest')->willReturn('127.0.0.1');
        $clock = $this->createMock(ClockInterface::class);
        $action = new SessionAction(
            $this->createMock(SessionManager::class),
            $this->createMock(SessionLockService::class),
            new SessionLockPolicy(new Config(['session' => ['lock_after_minutes' => 15]])),
            $this->createMock(PasskeyCredentialRepository::class),
            $this->createMock(PasskeyService::class),
            $ceremonies,
            $logger,
            $ipMatcher,
            $clock,
            $bruteForce,
            $this->createMock(SessionCookieFactory::class),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/auth/session/unlock/verify')
            ->withHeader('User-Agent', 'PHPUnit')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_TOKEN, str_repeat('a', 64))
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 17])
            ->withAttribute(AuthMiddleware::ATTR_SESSION, ['assurance_level' => 'strong'])
            ->withParsedBody(['flow_token' => 'flow-token']);

        $response = $action->unlockVerify($request, (new ResponseFactory())->createResponse());

        self::assertSame(401, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('session_unlock_failed', $body['error']['code']);
    }
}
