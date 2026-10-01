<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Http;

use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Amtgard\Denarius\Utilities\Log\IdpHttpTrafficLog;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/** PSR-18 wrapper that records IDP request/response detail when dev logging is enabled. */
final class LoggingIdpHttpClient implements ClientInterface
{
    public function __construct(
        private readonly ClientInterface $inner,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return DenariusLog::trace(__METHOD__, function () use ($request): ResponseInterface {
            try {
                $response = $this->inner->sendRequest($request);
                IdpHttpTrafficLog::record($request, $response);

                return $response;
            } catch (Throwable $thrown) {
                IdpHttpTrafficLog::record($request, null, $thrown);
                throw $thrown;
            }
        });
    }
}
