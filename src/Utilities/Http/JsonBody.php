<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Http;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class JsonBody
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function write(\Psr\Http\Message\ResponseInterface $response, array $payload, int $status = 200): \Psr\Http\Message\ResponseInterface
    {
        return DenariusLog::trace(__METHOD__, static function () use ($response, $payload, $status): \Psr\Http\Message\ResponseInterface {
            $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus($status);
        });
    }
}
