<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Controller;

use Amtgard\Denarius\Service\Access\AccountNavBuilder;
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
        private readonly AccountNavBuilder $accountNav,
        private readonly string $root,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function home(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return DenariusLog::trace(__METHOD__, function () use ($request, $response): ResponseInterface {
            $session = $this->auth->get();
            $accountActions = $session === null
                ? []
                : $this->accountNav->actionsFor((string) $session->profile->id);

            return $this->html->html($response, 'home.twig', [
                'authenticated' => $session !== null,
                'accountActions' => $accountActions,
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

    public function privacyPolicy(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return DenariusLog::trace(__METHOD__, function () use ($response): ResponseInterface {
            return $this->html->html($response, 'privacy-policy.twig', [
                'effectiveDate' => 'September 30, 2026',
                'contactEmail' => 'privacy@amtgard.com',
            ]);
        });
    }
}
