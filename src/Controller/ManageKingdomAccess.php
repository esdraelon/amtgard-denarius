<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Controller;

use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Persistence\Repository\Kingdom\KingdomRepositoryInterface;
use Amtgard\Denarius\Service\Access\PermissionService;
use Amtgard\Denarius\Utilities\Http\TwigHtmlRenderer;
use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Amtgard\IdpClient\Session\SessionAuthStore;
use Psr\Http\Message\ResponseInterface;

/** Guard: resolve a kingdom the current session may manage. */
final class ManageKingdomAccess
{
    public function __construct(
        private readonly SessionAuthStore $auth,
        private readonly PermissionService $permissions,
        private readonly KingdomRepositoryInterface $kingdoms,
        private readonly TwigHtmlRenderer $html,
    ) {
        DenariusLog::enter(__METHOD__);
    }

    public function resolveManaged(ResponseInterface $response, string $slug): KingdomRecord|ResponseInterface
    {
        return DenariusLog::trace(__METHOD__, function () use ($response, $slug): KingdomRecord|ResponseInterface {
            $session = $this->auth->get();
            if ($session === null) {
                return $response->withHeader('Location', '/login')->withStatus(302);
            }
            $kingdom = $this->kingdoms->findBySlug($slug);
            if ($kingdom === null) {
                return $this->html->html($response, 'message.twig', ['title' => 'Not found', 'message' => 'That kingdom is not assigned.'], 404);
            }
            $userId = (string) $session->profile->id;
            $allowed = $this->permissions->isAdmin($userId)
                || in_array($kingdom->getOrkKingdomId(), $this->permissions->managedKingdomIds($userId), true);
            if (!$allowed) {
                return $this->html->html($response, 'message.twig', ['title' => 'Forbidden', 'message' => 'You do not manage this kingdom.'], 403);
            }

            return $kingdom;
        });
    }
}
