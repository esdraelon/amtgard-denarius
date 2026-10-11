<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Driver\Transaction;

use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Persistence\Repository\Transaction\Impl\OrmTransactionRepository;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Driver: AARO 1.7 kingdom transaction listing (toggle via TRANSACTION_READ_DRIVER=aaro). */
final class AaroTransactionReadDriver implements TransactionReadDriver
{
    public function __construct(
        private readonly OrmTransactionRepository $transactions,
    ) {
        DenariusLog::enter(__METHOD__);
    }

    public function listForKingdom(int $kingdomId): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdomId): array {
            return $this->transactions->recordsForKingdom($kingdomId);
        });
    }

    public function findByTellerTransactionId(string $tellerTransactionId): ?TransactionRecord
    {
        return DenariusLog::trace(__METHOD__, function () use ($tellerTransactionId): ?TransactionRecord {
            return $this->transactions->findByTellerTransactionId($tellerTransactionId);
        });
    }
}
