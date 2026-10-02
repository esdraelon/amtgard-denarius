<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Log;

use Optional\Optional;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Middleware: bind X-Request-Id (or a generated id) into RequestLogContext. */
final class CorrelationMiddleware implements MiddlewareInterface
{
    private const HEADER = 'X-Request-Id';
    private const PATTERN = '/^[A-Za-z0-9_-]{8,64}$/';

    public function __construct()
    {
        $entered = DenariusLog::enter(__METHOD__);
        unset($entered);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return DenariusLog::trace(__METHOD__, function () use ($request, $handler): ResponseInterface {
            RequestLogContext::set($this->resolveId($request));

            return $handler->handle($request);
        });
    }

    private function resolveId(ServerRequestInterface $request): string
    {
        $raw = $request->getHeaderLine(self::HEADER);

        return Optional::ofNullable($raw === '' ? null : $raw)
            ->filter(static fn (string $value): bool => (bool) preg_match(self::PATTERN, $value))
            ->orElse(bin2hex(random_bytes(8)));
    }
}
