<?php

declare(strict_types=1);

namespace MyInvoice\Middleware;

use MyInvoice\Http\Json;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\SessionLockService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

final class SessionLockMiddleware implements MiddlewareInterface
{
    private const LOCKED_ALLOWED = [
        '/api/auth/logout',
        '/api/auth/session/status',
        '/api/auth/session/unlock/options',
        '/api/auth/session/unlock/verify',
    ];

    private const PUBLIC_PATHS = [
        '/api/health',
        '/api/version',
        '/api/openapi.yaml',
        '/api/docs',
        '/api/reference',
        '/api/scalar',
        '/api/auth/setup-status',
        '/api/auth/setup',
        '/api/auth/setup-ares-lookup',
        '/api/auth/setup-crpdph-lookup',
        '/api/auth/setup-sample',
        '/api/auth/login',
        '/api/auth/webauthn/login/verify',
        '/api/auth/forgot',
        '/api/auth/reset',
    ];

    public function __construct(
        private readonly SessionLockService $locks,
        private readonly ResponseFactory $responseFactory,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) !== 'session') {
            return $handler->handle($request);
        }
        $session = $request->getAttribute(AuthMiddleware::ATTR_SESSION);
        if (is_array($session) && ($session['assurance_level'] ?? 'legacy') === 'setup') {
            return $handler->handle($request);
        }
        $token = (string) $request->getAttribute(AuthMiddleware::ATTR_TOKEN, '');
        $result = $this->locks->evaluate($token);
        if (!$result->sessionExists || !$result->locked) {
            return $handler->handle($request);
        }

        if ($result->transitioned) {
            $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
            $userId = isset($user['id']) ? (int) $user['id'] : null;
            $this->logger->log(
                'auth.session_locked',
                $userId,
                'user',
                $userId,
                ['reason' => $result->reason],
                $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
                $request->getHeaderLine('User-Agent'),
            );
        }

        $path = $request->getUri()->getPath();
        if (in_array($path, self::LOCKED_ALLOWED, true)) {
            return $handler->handle($request);
        }
        if (in_array($path, self::PUBLIC_PATHS, true) || str_starts_with($path, '/api/public/')) {
            return $handler->handle(
                $request
                    ->withoutAttribute(AuthMiddleware::ATTR_USER)
                    ->withoutAttribute(AuthMiddleware::ATTR_SESSION)
                    ->withoutAttribute(AuthMiddleware::ATTR_TOKEN)
                    ->withoutAttribute(AuthMiddleware::ATTR_METHOD),
            );
        }

        return Json::error(
            $this->responseFactory->createResponse(423),
            'session_locked',
            'Session je zamčená a musí se znovu odemknout.',
            423,
        );
    }
}
