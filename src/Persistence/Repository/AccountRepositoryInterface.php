<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Repository;

use Amtgard\Denarius\Record\AccountRecord;

interface AccountRepositoryInterface
{
    /**
     * @return list<AccountRecord>
     */
    public function forKingdom(int $kingdomId): array;

    public function save(AccountRecord $account): AccountRecord;
}
