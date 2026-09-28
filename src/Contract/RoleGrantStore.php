<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Contract;

use Amtgard\Denarius\Record\RoleGrantRecord;

interface RoleGrantStore
{
    public function append(RoleGrantRecord $grant): void;
}
