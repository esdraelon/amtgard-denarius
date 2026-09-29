<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Http;

use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Middleware: reject POST forms without a valid CSRF token (except webhooks). */
final class PostCsrfMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responses,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = __METHOD__;

        return DenariusLog::trace(__METHOD__, function () use ($request, $handler, $method): ResponseInterface {
            if ($request->getMethod() !== 'POST' || str_starts_with($request->getUri()->getPath(), '/webhooks/')) {
                DenariusLog::debugBranch('csrf_skip', $method, [
                    'path' => $request->getUri()->getPath(),
                    'method' => $request->getMethod(),
                ]);

                return $handler->handle($request);
            }

            $body = $request->getParsedBody();
            $token = is_array($body) && isset($body['csrf']) ? (string) $body['csrf'] : null;
            if (CsrfToken::matches($token)) {
                DenariusLog::debugBranch('csrf_ok', $method, ['path' => $request->getUri()->getPath()]);

                return $handler->handle($request);
            }

            DenariusLog::warnBranch('csrf_reject', $method, ['path' => $request->getUri()->getPath()]);
            $response = $this->responses->createResponse(403);
            $response->getBody()->write('Forbidden');

            return $response->withHeader('Content-Type', 'text/plain');
        });
    }
}
