<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Access\Policy\Impl;

use Amtgard\Denarius\Domain\Access\Policy\VisibilityPolicy;
use Amtgard\Denarius\Domain\Access\AccessResult;
use Amtgard\Denarius\Domain\Access\Viewer;
use Amtgard\Denarius\Domain\Access\Visibility;

final class KingdomVisibilityPolicy implements VisibilityPolicy
{
    public function visibility(): Visibility
    {
        return Visibility::KingdomOnly;
    }

    public function decide(?Viewer $viewer, int $kingdomId): AccessResult
    {
        if ($viewer === null) {
            return AccessResult::Login;
        }

        if ($viewer->orkKingdomId === $kingdomId) {
            return AccessResult::Allow;
        }

        return AccessResult::Deny;
    }
}
