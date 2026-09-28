<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Http;

use Psr\Http\Message\ResponseInterface;
use Twig\Environment;

final class TwigHtmlRenderer
{
    public function __construct(
        private readonly Environment $twig,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function html(ResponseInterface $response, string $template, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write($this->twig->render($template, $data));

        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withStatus($status);
    }
}
