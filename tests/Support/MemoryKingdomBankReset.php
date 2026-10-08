<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Support;

use Amtgard\Denarius\Service\Enrollment\KingdomBankReset;
use Amtgard\Denarius\Tests\Unit\MemoryAccounts;
use Amtgard\Denarius\Tests\Unit\MemorySecrets;
use Amtgard\Denarius\Tests\Unit\MemoryTransactions;

/** In-memory bank reset for unit tests (PDO reset does not touch memory repositories). */
final class MemoryKingdomBankReset implements KingdomBankReset
{
    public function __construct(
        private readonly MemoryTransactions $transactions,
        private readonly MemoryAccounts $accounts,
        private readonly MemorySecrets $secrets,
    ) {
    }

    public function clearKingdom(int $kingdomId): void
    {
        unset($this->transactions->rows[$kingdomId], $this->accounts->rows[$kingdomId], $this->secrets->rows[$kingdomId]);
        foreach ($this->transactions->rowsByTellerId as $tellerId => $row) {
            if ($row->getKingdomId() === $kingdomId) {
                unset($this->transactions->rowsByTellerId[$tellerId]);
            }
        }
    }
}
