<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Repository\Account;

use Amtgard\Denarius\Persistence\Record\AccountRecord;

interface AccountRepositoryInterface
{
    /**
     * @return list<AccountRecord>
     */
    public function forKingdom(int $kingdomId): array;

    public function save(AccountRecord $account): AccountRecord;
}
