<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Http\Integ;

use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** DEV_INTEG: forwards IDP traffic only to non-production hosts (Decorator). */
final class IntegIdpHttpGuard implements ClientInterface
{
    /** @var list<string> */
    private const PRODUCTION_HOSTS = [
        'idp.amtgard.com',
    ];

    public function __construct(
        private readonly ClientInterface $inner,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($method, $request): ResponseInterface {
            $host = strtolower($request->getUri()->getHost());
            if (in_array($host, self::PRODUCTION_HOSTS, true)) {
                DenariusLog::infoBranch('integ_idp_production_blocked', $method, ['host' => $host]);
                throw new \RuntimeException('DEV_INTEG blocks production IDP host: ' . $host);
            }

            DenariusLog::infoBranch('integ_idp_http_forwarded', $method, ['host' => $host]);

            return $this->inner->sendRequest($request);
        });
    }
}
