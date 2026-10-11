<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Controller;

use Amtgard\Denarius\Utilities\Http\CsrfToken;
use Amtgard\Denarius\Utilities\Http\JsonBody;
use Amtgard\Denarius\Utilities\Http\TwigHtmlRenderer;
use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Psr\Http\Message\ResponseInterface;

/** Template Method: validate manage POST CSRF tokens. */
final class ManageCsrfGuard
{
    public function __construct(
        private readonly TwigHtmlRenderer $html,
    ) {
        DenariusLog::enter(__METHOD__);
    }

    /**
     * @param array<string, mixed> $body
     */
    public function rejectHtmlIfInvalid(ResponseInterface $response, array $body): ?ResponseInterface
    {
        return DenariusLog::trace(__METHOD__, function () use ($response, $body): ?ResponseInterface {
            if (CsrfToken::matches(isset($body['csrf']) ? (string) $body['csrf'] : null)) {
                return null;
            }

            return $this->html->html($response, 'message.twig', [
                'title' => 'Forbidden',
                'message' => 'The form token did not match.',
            ], 403);
        });
    }

    /**
     * @param array<string, mixed> $body
     */
    public function rejectJsonIfInvalid(ResponseInterface $response, array $body): ?ResponseInterface
    {
        return DenariusLog::trace(__METHOD__, function () use ($response, $body): ?ResponseInterface {
            if (CsrfToken::matches(isset($body['csrf']) ? (string) $body['csrf'] : null)) {
                return null;
            }

            return JsonBody::write($response, ['error' => 'The form token did not match.'], 403);
        });
    }
}
