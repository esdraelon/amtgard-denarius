<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain;

use Optional\Optional;

final class KingdomAccess
{
    public function decide(Visibility $visibility, ?Viewer $viewer, int $kingdomId): AccessResult
    {
        if ($visibility === Visibility::Public) {
            return AccessResult::Allow;
        }

        if ($viewer === null) {
            return AccessResult::Login;
        }

        if ($visibility === Visibility::Registered) {
            return AccessResult::Allow;
        }

        $matches = Optional::ofNullable($viewer->orkKingdomId)
            ->filter(static fn (int $id): bool => $id === $kingdomId)
            ->isPresent();

        return $matches ? AccessResult::Allow : AccessResult::Deny;
    }
}
