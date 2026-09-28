<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Controller;

use Amtgard\Denarius\Http\JsonBody;
use Amtgard\Denarius\Service\ProviderWebhookHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class WebhookController
{
    public function __construct(
        private readonly ProviderWebhookHandler $handler,
    ) {
    }

    public function teller(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->accept('teller', $request, $response);
    }

    public function stripe(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->accept('stripe', $request, $response);
    }

    public function plaid(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->accept('plaid', $request, $response);
    }

    private function accept(string $providerId, ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $signature = $request->getHeaderLine($this->handler->signatureHeader($providerId));
        $ok = $this->handler->handle(
            $providerId,
            (string) $request->getBody(),
            $signature !== '' ? $signature : null,
            time(),
        );

        return JsonBody::write($response, ['ok' => $ok], $ok ? 200 : 400);
    }
}
