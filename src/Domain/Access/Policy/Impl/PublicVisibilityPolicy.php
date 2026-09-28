<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Access\Policy\Impl;

use Amtgard\Denarius\Domain\Access\Policy\VisibilityPolicy;
use Amtgard\Denarius\Domain\Access\AccessResult;
use Amtgard\Denarius\Domain\Access\Viewer;
use Amtgard\Denarius\Domain\Access\Visibility;

final class PublicVisibilityPolicy implements VisibilityPolicy
{
    public function visibility(): Visibility
    {
        return Visibility::Public;
    }

    public function decide(?Viewer $viewer, int $kingdomId): AccessResult
    {
        return AccessResult::Allow;
    }
}
