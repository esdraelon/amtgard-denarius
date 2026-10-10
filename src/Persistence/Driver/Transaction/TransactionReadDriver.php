<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Driver\Transaction;

use Amtgard\Denarius\Persistence\Record\TransactionRecord;

/** Driver: read-optimized transaction queries (bypasses ORM row iteration). */
interface TransactionReadDriver
{
    /**
     * @return list<TransactionRecord>
     */
    public function listForKingdom(int $kingdomId): array;

    public function findByTellerTransactionId(string $tellerTransactionId): ?TransactionRecord;
}
