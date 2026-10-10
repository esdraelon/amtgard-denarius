<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Repository\Transaction\Impl;

use Amtgard\Denarius\Persistence\Driver\Transaction\TransactionReadDriver;
use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Persistence\Repository\Transaction\TransactionRepositoryInterface;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Repository: composes ORM writes with driver-backed kingdom listing. */
final class TransactionRepository implements TransactionRepositoryInterface
{
    public function __construct(
        private readonly OrmTransactionRepository $orm,
        private readonly TransactionReadDriver $reads,
    ) {
        DenariusLog::enter(__METHOD__);
    }

    public function upsert(TransactionRecord $transaction): void
    {
        DenariusLog::trace(__METHOD__, function () use ($transaction): mixed {
            $this->orm->upsert($transaction);

            return null;
        });
    }

    public function findByTellerTransactionId(string $tellerTransactionId): ?TransactionRecord
    {
        return DenariusLog::trace(__METHOD__, function () use ($tellerTransactionId): ?TransactionRecord {
            return $this->reads->findByTellerTransactionId($tellerTransactionId);
        });
    }

    public function forKingdom(int $kingdomId): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdomId): array {
            return $this->reads->listForKingdom($kingdomId);
        });
    }

    public function forKingdomPublished(int $kingdomId): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdomId): array {
            return array_values(array_filter(
                $this->reads->listForKingdom($kingdomId),
                static fn (TransactionRecord $row): bool => $row->getPublishedAt() !== null && $row->getPublishedAt() !== '',
            ));
        });
    }

    public function markPublished(int $kingdomId, string $tellerTransactionId, string $publishedAt): void
    {
        DenariusLog::trace(__METHOD__, function () use ($kingdomId, $tellerTransactionId, $publishedAt): mixed {
            $this->orm->markPublished($kingdomId, $tellerTransactionId, $publishedAt);

            return null;
        });
    }

    public function markUnpublished(int $kingdomId, string $tellerTransactionId): void
    {
        DenariusLog::trace(__METHOD__, function () use ($kingdomId, $tellerTransactionId): mixed {
            $this->orm->markUnpublished($kingdomId, $tellerTransactionId);

            return null;
        });
    }
}
