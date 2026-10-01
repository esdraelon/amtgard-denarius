<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Controller;

use Amtgard\Denarius\Utilities\Auth\CurrentActor;
use Amtgard\Denarius\Persistence\Repository\Kingdom\KingdomRepositoryInterface;
use Amtgard\Denarius\Utilities\Auth\PolicyGateway;
use Amtgard\Denarius\Persistence\Repository\Principal\PrincipalRepositoryInterface;
use Amtgard\Denarius\Persistence\Repository\RoleGrant\RoleGrantRepositoryInterface;
use Amtgard\Denarius\Utilities\Http\CsrfToken;
use Amtgard\Denarius\Utilities\Http\JsonBody;
use Amtgard\Denarius\Utilities\Http\OrkKingdomDirectory;
use Amtgard\Denarius\Utilities\Http\TwigHtmlRenderer;
use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Amtgard\Denarius\Service\Admin\AdminCommandRegistry;
use Amtgard\Denarius\Service\Admin\AdminGrantedRoleIndex;
use Amtgard\Denarius\Service\Admin\AdminGrantTargetResolver;
use Amtgard\Denarius\Service\Access\PermissionService;
use Amtgard\Denarius\Service\Admin\RoleAdmin;
use Amtgard\IdpClient\Exception\ClientIamException;
use Amtgard\IdpClient\Exception\ErrorCode;
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
        private readonly OrkKingdomDirectory $orkKingdoms,
        private readonly AdminGrantTargetResolver $grantTargets,
        private readonly AdminGrantedRoleIndex $grantedRoles,
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
            $query = $request->getQueryParams();
            $term = trim((string) ($query['email'] ?? ''));
            $permEmail = trim((string) ($query['perm_email'] ?? ''));
            $permKingdom = trim((string) ($query['perm_kingdom'] ?? ''));

            $principalViews = [];
            foreach ($term === '' ? [] : $this->principals->searchByEmail($term) as $principal) {
                $principalViews[] = $this->grantTargets->viewForAdmin($principal);
            }

            return $this->html->html($response, 'admin.twig', [
                'csrf' => CsrfToken::issue(),
                'email' => $term,
                'perm_email' => $permEmail,
                'perm_kingdom' => $permKingdom,
                'principals' => $principalViews,
                'grantedPermissions' => $this->grantedRoles->search($permEmail, $permKingdom),
            ]);
        });
    }

    public function kingdoms(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return DenariusLog::trace(__METHOD__, function () use ($response): ResponseInterface {
            $denied = $this->guardJson($response);
            if ($denied !== null) {
                return $denied;
            }

            return JsonBody::write($response, ['kingdoms' => $this->orkKingdoms->list()]);
        });
    }

    public function principalSuggestions(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return DenariusLog::trace(__METHOD__, function () use ($request, $response): ResponseInterface {
            $denied = $this->guardJson($response);
            if ($denied !== null) {
                return $denied;
            }
            $term = trim((string) ($request->getQueryParams()['q'] ?? ''));
            if ($term === '' || strlen($term) < 2) {
                return JsonBody::write($response, ['suggestions' => []]);
            }

            $suggestions = array_map(
                static fn ($principal) => $principal->view(),
                $this->principals->searchByEmail($term),
            );

            return JsonBody::write($response, ['suggestions' => $suggestions]);
        });
    }

    public function grant(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($request, $response, $method): ResponseInterface {
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
            try {
                $body['idp_user_id'] = $this->grantTargets->resolveIdpUserId($body);
            } catch (\InvalidArgumentException $exception) {
                DenariusLog::warnBranch('grant_target_resolve_failed', $method, [
                    'target_email' => trim((string) ($body['target_email'] ?? '')),
                    'form_idp_user_id' => trim((string) ($body['idp_user_id'] ?? '')),
                    'action' => trim((string) ($body['action'] ?? '')),
                    'reason' => $exception->getMessage(),
                ]);

                return $this->html->html($response, 'message.twig', [
                    'title' => 'Could not resolve grant target',
                    'message' => $exception->getMessage(),
                ], 400);
            }

            $admin = new RoleAdmin($this->policies, $this->permissions, $this->kingdoms, $this->grants, $actor);
            try {
                $this->commands->find((string) ($body['action'] ?? ''))->execute($admin, $body);
            } catch (ClientIamException $exception) {
                DenariusLog::warnBranch('client_iam_unavailable', __METHOD__, [
                    'surface' => 'admin_grant',
                    'error_code' => $exception->errorCode()->value,
                    'idp_error' => $exception->idpError(),
                    'target_idp_user_id' => isset($body['idp_user_id']) ? (string) $body['idp_user_id'] : null,
                ]);

                return $this->html->html($response, 'message.twig', [
                    'title' => 'Could not update IDP roles',
                    'message' => $this->clientIamGrantFailureMessage($exception, $body),
                ], $this->clientIamGrantFailureStatus($exception));
            }

            $email = trim((string) ($body['target_email'] ?? ''));
            $location = $email !== ''
                ? '/admin?email=' . rawurlencode($email)
                : '/admin';

            return $response->withHeader('Location', $location)->withStatus(302);
        });
    }

    /**
     * @param array<string, mixed> $body
     */
    private function clientIamGrantFailureMessage(ClientIamException $exception, array $body): string
    {
        $idpError = $exception->idpError();
        $targetId = isset($body['idp_user_id']) ? (string) $body['idp_user_id'] : 'the selected user';

        $message = match ($exception->errorCode()) {
            ErrorCode::ClientIamUnauthorized => 'The IDP rejected Denarius Client IAM credentials (HTTP 401). '
                . 'Check IDP_CLIENT_ID and IDP_CLIENT_SECRET for this OAuth client and that Client IAM is enabled.',
            ErrorCode::ClientIamNotFound => $idpError === 'unknown idp_user_id'
                ? sprintf(
                    'Client IAM policy claims do not recognize idp_user_id %s (unknown idp_user_id). '
                    . 'Use the IdP user UUID from userinfo id or JWT sub, or resolve the account with '
                    . 'GET /resources/client/users/by-email before POST /resources/client/policy-claims.',
                    $targetId,
                )
                : 'The IDP returned not found for this Client IAM policy-claims request.',
            ErrorCode::ClientIamUnexpectedStatus => 'The IDP returned a server error while reading or writing Client IAM policy claims '
                . '(GET/POST/DELETE /resources/client/policy-claims). Check IDP application logs.',
            ErrorCode::ClientIamValidation => 'The IDP rejected the policy claim shape (HTTP 400). '
                . 'Confirm this OAuth client has iam_service Denarius and service_format Configuration and Kingdom.',
            default => 'Denarius could not complete the IDP Client IAM grant or revoke call.',
        };

        if ($idpError !== null && $idpError !== '') {
            $message .= ' IDP error: ' . $idpError . '.';
        }

        return $message;
    }

    private function clientIamGrantFailureStatus(ClientIamException $exception): int
    {
        return match ($exception->errorCode()) {
            ErrorCode::ClientIamUnauthorized, ErrorCode::ClientIamMissingSecret => 503,
            default => 502,
        };
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

    private function guardJson(ResponseInterface $response): ?ResponseInterface
    {
        $method = __METHOD__;

        return DenariusLog::trace(__METHOD__, function () use ($response, $method): ?ResponseInterface {
            $session = $this->auth->get();
            if ($session === null) {
                DenariusLog::infoBranch('auth_login_required', $method, []);

                return JsonBody::write($response, ['error' => 'login_required'], 401);
            }
            if (!$this->permissions->isAdmin((string) $session->profile->id)) {
                DenariusLog::warnBranch('auth_admin_denied', $method, [
                    'idp_user_id' => (string) $session->profile->id,
                ]);

                return JsonBody::write($response, ['error' => 'forbidden'], 403);
            }

            return null;
        });
    }
}
