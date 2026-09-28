<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Contract;

use Amtgard\Denarius\Record\TransactionRecord;

interface TransactionStore
{
    public function upsert(TransactionRecord $transaction): void;

    /**
     * @return list<TransactionRecord>
     */
    public function forKingdom(int $kingdomId): array;
}
