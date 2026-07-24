<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Middleware;

use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SessionLockMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\SessionLockResult;
use MyInvoice\Service\Auth\SessionLockService;
use MyInvoice\Service\IpMatcher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

#[AllowMockObjectsWithoutExpectations]
final class SessionLockMiddlewareTest extends TestCase
{
    public function testLockedBusinessRequestReturns423(): void
    {
        $locks = $this->createMock(SessionLockService::class);
        $locks->expects(self::once())
            ->method('evaluate')
            ->with(str_repeat('a', 64))
            ->willReturn(SessionLockResult::locked(
                new \DateTimeImmutable('2026-07-24 12:00:00 UTC'),
                new \DateTimeImmutable('2026-07-24 12:15:00 UTC'),
                'idle',
                false,
            ));
        $response = $this->middleware($locks)->process(
            $this->sessionRequest('/api/invoices'),
            $this->handler(),
        );

        self::assertSame(423, $response->getStatusCode());
        self::assertSame(
            'session_locked',
            json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['error']['code'],
        );
    }

    public function testSessionMissingDuringLockEvaluationFailsClosed(): void
    {
        $locks = $this->createMock(SessionLockService::class);
        $locks->expects(self::once())
            ->method('evaluate')
            ->with(str_repeat('a', 64))
            ->willReturn(SessionLockResult::missing());
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $response = $this->middleware($locks)->process(
            $this->sessionRequest('/api/invoices'),
            $handler,
        );

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(
            'session_expired',
            json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['error']['code'],
        );
    }

    public function testLockedStatusAndUnlockRoutesPassThrough(): void
    {
        foreach ([
            '/api/auth/session/status',
            '/api/auth/session/unlock/options',
            '/api/auth/session/unlock/verify',
            '/api/auth/logout',
        ] as $path) {
            $locks = $this->createMock(SessionLockService::class);
            $locks->method('evaluate')->willReturn(SessionLockResult::locked(
                new \DateTimeImmutable('2026-07-24 12:00:00 UTC'),
                new \DateTimeImmutable('2026-07-24 12:15:00 UTC'),
                'idle',
                false,
            ));
            $response = $this->middleware($locks)->process(
                $this->sessionRequest($path),
                $this->handler(),
            );
            self::assertSame(204, $response->getStatusCode(), $path);
        }
    }

    public function testLockedPublicRequestCannotUseOptionalAuthenticatedContext(): void
    {
        $locks = $this->createMock(SessionLockService::class);
        $locks->method('evaluate')->willReturn(SessionLockResult::locked(
            new \DateTimeImmutable('2026-07-24 12:00:00 UTC'),
            new \DateTimeImmutable('2026-07-24 12:15:00 UTC'),
            'manual',
            false,
        ));
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                TestCase::assertNull($request->getAttribute(AuthMiddleware::ATTR_USER));
                TestCase::assertNull($request->getAttribute(AuthMiddleware::ATTR_SESSION));
                TestCase::assertNull($request->getAttribute(AuthMiddleware::ATTR_TOKEN));
                TestCase::assertNull($request->getAttribute(AuthMiddleware::ATTR_METHOD));
                return (new ResponseFactory())->createResponse(204);
            }
        };

        $response = $this->middleware($locks)->process(
            $this->sessionRequest('/api/public/invoice/synthetic'),
            $handler,
        );
        self::assertSame(204, $response->getStatusCode());
    }

    public function testSetupSessionIsNotEvaluatedByIdleLock(): void
    {
        $locks = $this->createMock(SessionLockService::class);
        $locks->expects(self::never())->method('evaluate');

        $request = $this->sessionRequest('/api/auth/totp/status')
            ->withAttribute(AuthMiddleware::ATTR_SESSION, [
                'csrf_token' => str_repeat('b', 64),
                'assurance_level' => 'setup',
            ]);

        self::assertSame(204, $this->middleware($locks)->process(
            $request,
            $this->handler(),
        )->getStatusCode());
    }

    private function middleware(SessionLockService $locks): SessionLockMiddleware
    {
        return new SessionLockMiddleware(
            $locks,
            new ResponseFactory(),
            $this->createMock(ActivityLogger::class),
            $this->createMock(IpMatcher::class),
        );
    }

    private function sessionRequest(string $path): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', $path)
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_TOKEN, str_repeat('a', 64))
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 17])
            ->withAttribute(AuthMiddleware::ATTR_SESSION, ['csrf_token' => str_repeat('b', 64)]);
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(204);
            }
        };
    }
}
