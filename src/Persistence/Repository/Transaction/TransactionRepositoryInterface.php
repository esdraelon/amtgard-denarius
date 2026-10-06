<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Persistence\Repository\Transaction;

use Amtgard\Denarius\Persistence\Record\TransactionRecord;

interface TransactionRepositoryInterface
{
    public function upsert(TransactionRecord $transaction): void;

    public function findByTellerTransactionId(string $tellerTransactionId): ?TransactionRecord;

    /**
     * @return list<TransactionRecord>
     */
    public function forKingdom(int $kingdomId): array;

    /**
     * Rows manager-published to the public statement (`published_at` set).
     *
     * @return list<TransactionRecord>
     */
    public function forKingdomPublished(int $kingdomId): array;

    public function markPublished(int $kingdomId, string $tellerTransactionId, string $publishedAt): void;

    public function markUnpublished(int $kingdomId, string $tellerTransactionId): void;
}
