<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Access;

use Amtgard\Denarius\Service\Kingdom\ManagedKingdomResolver;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class AccountNavBuilder
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly ManagedKingdomResolver $managedKingdoms,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    /**
     * Primary signed-in navigation actions from IdP policy (admin and kingdom manager compose).
     *
     * @return list<array{label: string, href: string}>
     */
    public function actionsFor(string $idpUserId): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($idpUserId): array {
            $actions = [];
            if ($this->permissions->isAdmin($idpUserId)) {
                $actions[] = ['label' => 'Admin', 'href' => '/admin'];
            }

            foreach ($this->permissions->managedKingdomIds($idpUserId) as $orkKingdomId) {
                $kingdom = $this->managedKingdoms->resolve($orkKingdomId);
                if ($kingdom === null) {
                    continue;
                }
                $actions[] = [
                    'label' => 'Manage ' . (string) $kingdom->getName(),
                    'href' => '/manage/' . (string) $kingdom->getSlug(),
                ];
            }

            return $actions;
        });
    }
}
