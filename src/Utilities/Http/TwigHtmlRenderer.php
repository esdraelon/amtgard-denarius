<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Http;

use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Psr\Http\Message\ResponseInterface;
use Twig\Environment;

final class TwigHtmlRenderer
{
    public function __construct(
        private readonly Environment $twig,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function html(ResponseInterface $response, string $template, array $data, int $status = 200): ResponseInterface
    {
        return DenariusLog::trace(__METHOD__, function () use ($response, $template, $data, $status): ResponseInterface {
            $response->getBody()->write($this->twig->render($template, $data));

            return $response
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withStatus($status);
        });
    }
}
