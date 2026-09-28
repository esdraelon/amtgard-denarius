<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Controller;

use Amtgard\Denarius\Http\BuildInfo;
use Amtgard\Denarius\Http\CsrfToken;
use Amtgard\Denarius\Http\JsonBody;
use Amtgard\Denarius\Http\TwigHtmlRenderer;
use Amtgard\IdpClient\Session\SessionAuthStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class HomeController
{
    public function __construct(
        private readonly TwigHtmlRenderer $html,
        private readonly SessionAuthStore $auth,
        private readonly string $root,
    ) {
    }

    public function home(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->html->html($response, 'home.twig', [
            'authenticated' => $this->auth->isAuthenticated(),
            'csrf' => CsrfToken::issue(),
            'version' => BuildInfo::version($this->root),
        ]);
    }

    public function version(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return JsonBody::write($response, ['version' => BuildInfo::version($this->root)]);
    }
}
