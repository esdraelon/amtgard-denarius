<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Access;

use Amtgard\Denarius\Domain\AccessResult;
use Amtgard\Denarius\Domain\Viewer;
use Amtgard\Denarius\Domain\Visibility;

final class RegisteredVisibilityPolicy implements VisibilityPolicy
{
    public function visibility(): Visibility
    {
        return Visibility::Registered;
    }

    public function decide(?Viewer $viewer, int $kingdomId): AccessResult
    {
        if ($viewer === null) {
            return AccessResult::Login;
        }

        return AccessResult::Allow;
    }
}
