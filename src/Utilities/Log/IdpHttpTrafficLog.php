<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Log;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/** Dev-oriented IDP Client IAM / resource HTTP exchange logging. */
final class IdpHttpTrafficLog
{
    public const BRANCH = 'idp_http_exchange';

    public const LOG_METHOD = 'Amtgard\\Denarius\\Utilities\\Log\\IdpHttpTrafficLog::record';

    private const MAX_BODY = 65_536;

    public static function verbose(): bool
    {
        return ($_ENV['APP_DEBUG'] ?? 'false') === 'true'
            && ($_ENV['DENARIUS_IDP_HTTP_LOG'] ?? 'true') !== 'false';
    }

    public static function plaintextExchanges(): bool
    {
        if (($_ENV['APP_DEBUG'] ?? 'false') !== 'true') {
            return false;
        }

        return ($_ENV['DENARIUS_IDP_HTTP_LOG_PLAINTEXT'] ?? 'true') !== 'false';
    }

    public static function record(RequestInterface $request, ?ResponseInterface $response, ?Throwable $error = null): void
    {
        if (! self::verbose()) {
            return;
        }

        DenariusLog::trace(__METHOD__, function () use ($request, $response, $error): null {
            $requestBody = self::snapshotBody($request->getBody());
            $responseBody = $response !== null ? self::snapshotBody($response->getBody()) : null;

            $method = IdpHttpTrafficLog::LOG_METHOD;

            DenariusLog::debugBranch(self::BRANCH, $method, [
                'request' => [
                    'method' => $request->getMethod(),
                    'uri' => (string) $request->getUri(),
                    'headers' => $request->getHeaders(),
                    'body' => $requestBody,
                ],
                'response' => $response === null ? null : [
                    'status' => $response->getStatusCode(),
                    'headers' => $response->getHeaders(),
                    'body' => $responseBody,
                ],
                'error' => $error === null ? null : [
                    'class' => $error::class,
                    'message' => $error->getMessage(),
                ],
            ]);

            return null;
        });
    }

    private static function snapshotBody(\Psr\Http\Message\StreamInterface $stream): string
    {
        if (! $stream->isReadable()) {
            return '';
        }
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $raw = $stream->getContents();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        return self::truncate($raw);
    }

    private static function truncate(string $body): string
    {
        if (strlen($body) <= self::MAX_BODY) {
            return $body;
        }

        return substr($body, 0, self::MAX_BODY) . '...(truncated)';
    }
}
