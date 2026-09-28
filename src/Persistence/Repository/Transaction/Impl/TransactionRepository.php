<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Repository\Transaction\Impl;

use Amtgard\Denarius\Persistence\Repository\Transaction\TransactionRepositoryInterface;
use Amtgard\ActiveRecordOrm\Attribute\RepositoryOf;
use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\ActiveRecordOrm\Interface\EntityRepositoryInterface;
use Amtgard\ActiveRecordOrm\Query\OrderBy;
use Amtgard\Denarius\Persistence\Entity\TransactionEntity;
use Amtgard\Denarius\Persistence\Record\TransactionRecord;

#[RepositoryOf('transactions', TransactionEntity::class)]
class TransactionRepository extends Repository implements EntityRepositoryInterface, TransactionRepositoryInterface
{
    public static function getTableName(): string
    {
        return 'transactions';
    }

    public static function getEntityClass(): string
    {
        return TransactionEntity::class;
    }

    public function upsert(TransactionRecord $transaction): void
    {
        $existing = $this->fetchBy('teller_transaction_id', $transaction->getTellerTransactionId());
        $entity = $existing instanceof TransactionEntity ? $existing : $this->newRepositoryEntity();
        if (!$entity instanceof TransactionEntity) {
            throw new \RuntimeException('Transaction entity was not created.');
        }
        $this->fill($entity, $transaction);
        $this->persist($entity);
    }

    public function forKingdom(int $kingdomId): array
    {
        $this->clear();
        $this->kingdom_id = $kingdomId;
        $this->orderBy('posted_on', OrderBy::ASC);
        $this->orderBy('id', OrderBy::ASC);

        return $this->collected();
    }

    private function fill(TransactionEntity $entity, TransactionRecord $transaction): void
    {
        $entity->setKingdomId($transaction->getKingdomId());
        $entity->setTellerTransactionId($transaction->getTellerTransactionId());
        $entity->setTellerAccountId($transaction->getTellerAccountId());
        $entity->setPostedOn($transaction->getPostedOn());
        $entity->setAmountCents($transaction->getAmountCents());
        $entity->setCategory($transaction->getCategory());
        $entity->setDescription($transaction->getDescription());
        $entity->setCounterparty($transaction->getCounterparty());
        $entity->setStatus($transaction->getStatus());
    }

    private function record(mixed $entity): ?TransactionRecord
    {
        if (!$entity instanceof TransactionEntity) {
            return null;
        }

        return TransactionRecord::builder()
            ->id($entity->getId())
            ->kingdomId((int) $entity->getKingdomId())
            ->tellerTransactionId((string) $entity->getTellerTransactionId())
            ->tellerAccountId((string) $entity->getTellerAccountId())
            ->postedOn((string) $entity->getPostedOn())
            ->amountCents((int) $entity->getAmountCents())
            ->category((string) $entity->getCategory())
            ->description((string) $entity->getDescription())
            ->counterparty((string) $entity->getCounterparty())
            ->status((string) $entity->getStatus())
            ->build();
    }

    /**
     * @return list<TransactionRecord>
     */
    private function collected(): array
    {
        if ($this->find() === 0) {
            return [];
        }
        $rows = [];
        while ($this->next()) {
            $record = $this->record($this->getCurrent());
            if ($record !== null) {
                $rows[] = $record;
            }
        }

        return $rows;
    }
}
