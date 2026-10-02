<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Repository\RoleGrant;

use Amtgard\Denarius\Persistence\Record\RoleGrantRecord;

interface RoleGrantRepositoryInterface
{
    public function append(RoleGrantRecord $grant): void;

    /**
     * @return list<RoleGrantRecord>
     */
    public function listChronological(): array;
}
