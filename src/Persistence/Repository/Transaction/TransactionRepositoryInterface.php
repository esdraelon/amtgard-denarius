<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Repository\Transaction;

use Amtgard\Denarius\Persistence\Record\TransactionRecord;

interface TransactionRepositoryInterface
{
    public function upsert(TransactionRecord $transaction): void;

    /**
     * @return list<TransactionRecord>
     */
    public function forKingdom(int $kingdomId): array;
}
