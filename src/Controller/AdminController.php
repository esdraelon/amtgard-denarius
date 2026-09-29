<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Controller;

use Amtgard\Denarius\Utilities\Auth\CurrentActor;
use Amtgard\Denarius\Persistence\Repository\Kingdom\KingdomRepositoryInterface;
use Amtgard\Denarius\Utilities\Auth\PolicyGateway;
use Amtgard\Denarius\Persistence\Repository\Principal\PrincipalRepositoryInterface;
use Amtgard\Denarius\Persistence\Repository\RoleGrant\RoleGrantRepositoryInterface;
use Amtgard\Denarius\Utilities\Http\CsrfToken;
use Amtgard\Denarius\Utilities\Http\TwigHtmlRenderer;
use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Amtgard\Denarius\Service\Admin\AdminCommandRegistry;
use Amtgard\Denarius\Service\Access\PermissionService;
use Amtgard\Denarius\Service\Admin\RoleAdmin;
use Amtgard\IdpClient\Session\SessionAuthStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AdminController
{
    public function __construct(
        private readonly SessionAuthStore $auth,
        private readonly PermissionService $permissions,
        private readonly PrincipalRepositoryInterface $principals,
        private readonly KingdomRepositoryInterface $kingdoms,
        private readonly PolicyGateway $policies,
        private readonly RoleGrantRepositoryInterface $grants,
        private readonly TwigHtmlRenderer $html,
        private readonly AdminCommandRegistry $commands,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return DenariusLog::trace(__METHOD__, function () use ($request, $response): ResponseInterface {
            $denied = $this->guard($response);
            if ($denied !== null) {
                return $denied;
            }
            $term = trim((string) ($request->getQueryParams()['email'] ?? ''));

            return $this->html->html($response, 'admin.twig', [
                'csrf' => CsrfToken::issue(),
                'email' => $term,
                'principals' => array_map(static fn ($principal) => $principal->view(), $term === '' ? [] : $this->principals->searchByEmail($term)),
            ]);
        });
    }

    public function grant(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return DenariusLog::trace(__METHOD__, function () use ($request, $response): ResponseInterface {
            $denied = $this->guard($response);
            if ($denied !== null) {
                return $denied;
            }
            $body = (array) $request->getParsedBody();
            $rejected = $this->rejectedToken($response, $body);
            if ($rejected !== null) {
                return $rejected;
            }

            $actor = (string) $this->auth->get()->profile->id;
            CurrentActor::set($actor);
            $admin = new RoleAdmin($this->policies, $this->permissions, $this->kingdoms, $this->grants, $actor);
            $this->commands->find((string) ($body['action'] ?? ''))->execute($admin, $body);

            return $response->withHeader('Location', '/admin')->withStatus(302);
        });
    }

    /**
     * @param array<string, mixed> $body
     */
    private function rejectedToken(ResponseInterface $response, array $body): ?ResponseInterface
    {
        $method = __METHOD__;

        return DenariusLog::trace(__METHOD__, function () use ($response, $body, $method): ?ResponseInterface {
            if (CsrfToken::matches(isset($body['csrf']) ? (string) $body['csrf'] : null)) {
                return null;
            }

            DenariusLog::warnBranch('csrf_reject', $method, ['surface' => 'admin']);

            return $this->html->html($response, 'message.twig', ['title' => 'Forbidden', 'message' => 'The form token did not match.'], 403);
        });
    }

    private function guard(ResponseInterface $response): ?ResponseInterface
    {
        $method = __METHOD__;

        return DenariusLog::trace(__METHOD__, function () use ($response, $method): ?ResponseInterface {
            $session = $this->auth->get();
            if ($session === null) {
                DenariusLog::infoBranch('auth_login_required', $method, []);

                return $response->withHeader('Location', '/login')->withStatus(302);
            }
            if (!$this->permissions->isAdmin((string) $session->profile->id)) {
                DenariusLog::warnBranch('auth_admin_denied', $method, [
                    'idp_user_id' => (string) $session->profile->id,
                ]);

                return $this->html->html($response, 'message.twig', ['title' => 'Forbidden', 'message' => 'Denarius admin access is required.'], 403);
            }

            return null;
        });
    }
}
