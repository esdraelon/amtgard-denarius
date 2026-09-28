<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Controller;

use Amtgard\Denarius\Auth\CurrentActor;
use Amtgard\Denarius\Contract\KingdomStore;
use Amtgard\Denarius\Contract\PolicyGateway;
use Amtgard\Denarius\Contract\PrincipalStore;
use Amtgard\Denarius\Contract\RoleGrantStore;
use Amtgard\Denarius\Http\CsrfToken;
use Amtgard\Denarius\Http\TwigHtmlRenderer;
use Amtgard\Denarius\Service\Admin\AdminCommandRegistry;
use Amtgard\Denarius\Service\CachedKingdomDirectory;
use Amtgard\Denarius\Service\PermissionService;
use Amtgard\Denarius\Service\RoleAdmin;
use Amtgard\IdpClient\Session\SessionAuthStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AdminController
{
    public function __construct(
        private readonly SessionAuthStore $auth,
        private readonly PermissionService $permissions,
        private readonly CachedKingdomDirectory $directory,
        private readonly PrincipalStore $principals,
        private readonly KingdomStore $kingdoms,
        private readonly PolicyGateway $policies,
        private readonly RoleGrantStore $grants,
        private readonly TwigHtmlRenderer $html,
        private readonly AdminCommandRegistry $commands,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $denied = $this->guard($response);
        if ($denied !== null) {
            return $denied;
        }
        $term = trim((string) ($request->getQueryParams()['email'] ?? ''));

        return $this->html->html($response, 'admin.twig', [
            'csrf' => CsrfToken::issue(),
            'kingdoms' => $this->directory->list(),
            'email' => $term,
            'principals' => array_map(static fn ($principal) => $principal->view(), $term === '' ? [] : $this->principals->searchByEmail($term)),
        ]);
    }

    public function grant(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
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
    }

    /**
     * @param array<string, mixed> $body
     */
    private function rejectedToken(ResponseInterface $response, array $body): ?ResponseInterface
    {
        if (CsrfToken::matches(isset($body['csrf']) ? (string) $body['csrf'] : null)) {
            return null;
        }

        return $this->html->html($response, 'message.twig', ['title' => 'Forbidden', 'message' => 'The form token did not match.'], 403);
    }

    private function guard(ResponseInterface $response): ?ResponseInterface
    {
        $session = $this->auth->get();
        if ($session === null) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }
        if (!$this->permissions->isAdmin((string) $session->profile->id)) {
            return $this->html->html($response, 'message.twig', ['title' => 'Forbidden', 'message' => 'Denarius admin access is required.'], 403);
        }

        return null;
    }
}
