<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Repository;

use Amtgard\Denarius\Record\RoleGrantRecord;

interface RoleGrantRepositoryInterface
{
    public function append(RoleGrantRecord $grant): void;
}
