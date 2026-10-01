<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Controller;

use Amtgard\Denarius\Service\Enrollment\SimpleFinReturnEnrollment;
use Amtgard\Denarius\Service\Enrollment\SimpleFinReturnException;
use Amtgard\Denarius\Utilities\Http\CsrfToken;
use Amtgard\Denarius\Utilities\Http\TwigHtmlRenderer;
use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Amtgard\IdpClient\Session\SessionAuthStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class SimpleFinReturnController
{
    public function __construct(
        private readonly SessionAuthStore $auth,
        private readonly SimpleFinReturnEnrollment $enrollment,
        private readonly TwigHtmlRenderer $html,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return DenariusLog::trace(__METHOD__, function () use ($request, $response): ResponseInterface {
            $session = $this->auth->get();
            if ($session === null) {
                return $response->withHeader('Location', '/login')->withStatus(302);
            }
            $query = $request->getQueryParams();
            if ($this->hasSetupToken($query)) {
                return $this->finish($response, (string) $session->profile->id, $query);
            }

            return $this->html->html($response, 'simplefin-return.twig', [
                'csrf' => CsrfToken::issue(),
                'error' => '',
            ]);
        });
    }

    public function submit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return DenariusLog::trace(__METHOD__, function () use ($request, $response): ResponseInterface {
            $session = $this->auth->get();
            if ($session === null) {
                return $response->withHeader('Location', '/login')->withStatus(302);
            }
            $body = (array) $request->getParsedBody();
            if (!CsrfToken::matches(isset($body['csrf']) ? (string) $body['csrf'] : null)) {
                return $this->html->html($response, 'message.twig', ['title' => 'Forbidden', 'message' => 'The form token did not match.'], 403);
            }

            return $this->finish($response, (string) $session->profile->id, [
                'setup_token' => (string) ($body['setup_token'] ?? ''),
            ]);
        });
    }

    /**
     * @param array<string, mixed> $params
     */
    private function finish(ResponseInterface $response, string $actorId, array $params): ResponseInterface
    {
        return DenariusLog::trace(__METHOD__, function () use ($response, $actorId, $params): ResponseInterface {
            try {
                $location = $this->enrollment->complete($actorId, $params);

                return $response->withHeader('Location', $location)->withStatus(302);
            } catch (SimpleFinReturnException $exception) {
                DenariusLog::debugBranch('simplefin_return_failed', __METHOD__, [
                    'reason' => $exception->reason(),
                ]);

                return $this->html->html($response, 'simplefin-return.twig', [
                    'csrf' => CsrfToken::issue(),
                    'error' => $exception->getMessage(),
                ], 400);
            }
        });
    }

    /**
     * @param array<string, mixed> $query
     */
    private function hasSetupToken(array $query): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($query): bool {
            foreach (['setup_token', 'setupToken', 'token'] as $key) {
                if (trim((string) ($query[$key] ?? '')) !== '') {
                    return true;
                }
            }

            return false;
        });
    }
}
