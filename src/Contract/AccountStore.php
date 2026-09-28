<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Contract;

use Amtgard\Denarius\Record\AccountRecord;

interface AccountStore
{
    /**
     * @return list<AccountRecord>
     */
    public function forKingdom(int $kingdomId): array;

    public function save(AccountRecord $account): AccountRecord;
}
