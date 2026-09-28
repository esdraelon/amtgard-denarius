<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Controller;

use Amtgard\Denarius\Http\JsonBody;
use Amtgard\Denarius\Service\TellerWebhookHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class WebhookController
{
    public function __construct(
        private readonly TellerWebhookHandler $handler,
    ) {
    }

    public function teller(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $ok = $this->handler->handle(
            (string) $request->getBody(),
            $request->getHeaderLine('Teller-Signature') ?: null,
            time(),
        );

        return JsonBody::write($response, ['ok' => $ok], $ok ? 200 : 400);
    }
}
