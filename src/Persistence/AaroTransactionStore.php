<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence;

use Amtgard\Denarius\Contract\TransactionStore;
use Amtgard\Denarius\Persistence\Entity\TransactionEntity;
use Amtgard\Denarius\Persistence\Repository\TransactionRepository;
use Amtgard\Denarius\Record\TransactionRecord;

final class AaroTransactionStore implements TransactionStore
{
    use StoreSupport;

    public function upsert(TransactionRecord $transaction): void
    {
        $repo = $this->transactions();
        $existing = $repo->fetchBy('teller_transaction_id', $transaction->getTellerTransactionId());
        $entity = $existing instanceof TransactionEntity ? $existing : $repo->newRepositoryEntity();
        if (!$entity instanceof TransactionEntity) {
            throw new \RuntimeException('Transaction entity was not created.');
        }
        $entity->setKingdomId($transaction->getKingdomId());
        $entity->setTellerTransactionId($transaction->getTellerTransactionId());
        $entity->setTellerAccountId($transaction->getTellerAccountId());
        $entity->setPostedOn($transaction->getPostedOn());
        $entity->setAmountCents($transaction->getAmountCents());
        $entity->setCategory($transaction->getCategory());
        $entity->setDescription($transaction->getDescription());
        $entity->setCounterparty($transaction->getCounterparty());
        $entity->setStatus($transaction->getStatus());
        $repo->persist($entity);
    }

    public function forKingdom(int $kingdomId): array
    {
        $statement = $this->pdo()->prepare('SELECT id FROM transactions WHERE kingdom_id = :kingdom_id ORDER BY posted_on, id');
        $statement->execute(['kingdom_id' => $kingdomId]);
        $rows = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $entity = $this->transactions()->fetch((int) $row['id']);
            if ($entity instanceof TransactionEntity) {
                $rows[] = TransactionRecord::builder()
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
        }

        return $rows;
    }

    private function transactions(): TransactionRepository
    {
        return $this->repo(TransactionRepository::class);
    }
}
