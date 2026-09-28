<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Access;

use Amtgard\Denarius\Domain\AccessResult;
use Amtgard\Denarius\Domain\Viewer;
use Amtgard\Denarius\Domain\Visibility;

interface VisibilityPolicy
{
    public function visibility(): Visibility;

    public function decide(?Viewer $viewer, int $kingdomId): AccessResult;
}
