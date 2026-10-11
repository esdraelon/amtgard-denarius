<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Driver\Transaction;

use Amtgard\ActiveRecordOrm\Query\OrderBy;
use Amtgard\Denarius\Persistence\Entity\TransactionEntity;
use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Persistence\Repository\Transaction\Impl\OrmTransactionRepository;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Driver: AARO 1.7 kingdom transaction listing (toggle via TRANSACTION_READ_DRIVER). */
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
            return array_map(
                fn (TransactionEntity $entity): TransactionRecord => $this->record($entity),
                $this->entitiesForKingdom($kingdomId),
            );
        });
    }

    public function findByTellerTransactionId(string $tellerTransactionId): ?TransactionRecord
    {
        return DenariusLog::trace(__METHOD__, function () use ($tellerTransactionId): ?TransactionRecord {
            return $this->transactions->findByTellerTransactionId($tellerTransactionId);
        });
    }

    /**
     * @return list<TransactionEntity>
     */
    private function entitiesForKingdom(int $kingdomId): array
    {
        $this->transactions->clear();
        $this->transactions->kingdom_id = $kingdomId;
        $this->transactions->orderBy('posted_on', OrderBy::ASC);
        $this->transactions->orderBy('id', OrderBy::ASC);

        /** @var list<TransactionEntity> $entities */
        $entities = $this->transactions->collected();

        return $entities;
    }

    private function record(TransactionEntity $entity): TransactionRecord
    {
        $found = $this->transactions->findByTellerTransactionId((string) $entity->getTellerTransactionId());
        if ($found === null) {
            throw new \RuntimeException('Transaction row missing after AARO collect.');
        }

        return $found;
    }
}
