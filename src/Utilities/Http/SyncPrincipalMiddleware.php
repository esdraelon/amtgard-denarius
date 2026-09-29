<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Http;

use Amtgard\Denarius\Utilities\Auth\CurrentActor;
use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Amtgard\Denarius\Service\Access\PrincipalSync;
use Amtgard\IdpClient\Session\SessionAuthStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SyncPrincipalMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly SessionAuthStore $auth,
        private readonly PrincipalSync $principals,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return DenariusLog::trace(__METHOD__, function () use ($request, $handler): ResponseInterface {
            $session = $this->auth->get();
            if ($session !== null) {
                $ork = $session->profile->orkProfile;
                $this->principals->upsert(
                    (string) $session->profile->id,
                    $session->profile->email,
                    $ork?->kingdomId,
                    $ork?->kingdomName,
                );
                CurrentActor::set((string) $session->profile->id);
            } else {
                CurrentActor::set(null);
            }

            return $handler->handle($request);
        });
    }
}
