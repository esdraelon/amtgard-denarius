<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Access\Policy;

use Amtgard\Denarius\Domain\Access\AccessResult;
use Amtgard\Denarius\Domain\Access\Viewer;
use Amtgard\Denarius\Domain\Access\Visibility;

interface VisibilityPolicy
{
    public function visibility(): Visibility;

    public function decide(?Viewer $viewer, int $kingdomId): AccessResult;
}
