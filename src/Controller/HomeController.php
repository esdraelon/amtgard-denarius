<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Controller;

use Amtgard\Denarius\Utilities\Http\BuildInfo;
use Amtgard\Denarius\Utilities\Http\CsrfToken;
use Amtgard\Denarius\Utilities\Http\JsonBody;
use Amtgard\Denarius\Utilities\Http\TwigHtmlRenderer;
use Amtgard\Denarius\Utilities\Log\DenariusLog;
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
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function home(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return DenariusLog::trace(__METHOD__, function () use ($request, $response): ResponseInterface {
            return $this->html->html($response, 'home.twig', [
                'authenticated' => $this->auth->isAuthenticated(),
                'csrf' => CsrfToken::issue(),
                'version' => BuildInfo::version($this->root),
            ]);
        });
    }

    public function version(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return DenariusLog::trace(__METHOD__, function () use ($request, $response): ResponseInterface {
            return JsonBody::write($response, ['version' => BuildInfo::version($this->root)]);
        });
    }
}
