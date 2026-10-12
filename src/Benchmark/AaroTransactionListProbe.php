<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Benchmark;

use Amtgard\Denarius\Persistence\Driver\Transaction\AaroTransactionReadDriver;
use Amtgard\Denarius\Persistence\Repository\Transaction\Impl\OrmTransactionRepository;

/** Benchmark probe: delegates to {@see AaroTransactionReadDriver}. */
final class AaroTransactionListProbe
{
    private readonly AaroTransactionReadDriver $driver;

    public function __construct(OrmTransactionRepository $transactions)
    {
        $this->driver = new AaroTransactionReadDriver($transactions);
    }

    public function countForKingdom(int $kingdomId): int
    {
        return count($this->collectedForKingdom($kingdomId));
    }

    /**
     * @return list<\Amtgard\Denarius\Persistence\Record\TransactionRecord>
     */
    public function collectedForKingdom(int $kingdomId): array
    {
        return $this->driver->listForKingdom($kingdomId);
    }
}
