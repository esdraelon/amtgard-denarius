<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Access\Policy\Impl;

use Amtgard\Denarius\Domain\Access\Policy\VisibilityPolicy;
use Amtgard\Denarius\Domain\Access\AccessResult;
use Amtgard\Denarius\Domain\Access\Viewer;
use Amtgard\Denarius\Domain\Access\Visibility;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class RegisteredVisibilityPolicy implements VisibilityPolicy
{
    public function visibility(): Visibility
    {
        return DenariusLog::trace(__METHOD__, function (): Visibility {
            return Visibility::Registered;
        });
    }

    public function decide(?Viewer $viewer, int $kingdomId): AccessResult
    {
        return DenariusLog::trace(__METHOD__, function () use ($viewer): AccessResult {
            if ($viewer === null) {
                return AccessResult::Login;
            }

            return AccessResult::Allow;
        });
    }
}
