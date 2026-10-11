<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Benchmark;

use Amtgard\ActiveRecordOrm\Query\OrderBy;
use Amtgard\Denarius\Persistence\Repository\Transaction\Impl\OrmTransactionRepository;

/**
 * Benchmark probe: kingdom-scoped transaction list via AARO 1.7 find/next.
 * Future {@see \Amtgard\Denarius\Persistence\Driver\Transaction\AaroTransactionReadDriver} should match this shape.
 */
final class AaroTransactionListProbe
{
    public function __construct(
        private readonly OrmTransactionRepository $transactions,
    ) {
    }

    public function countForKingdom(int $kingdomId): int
    {
        return count($this->collectedForKingdom($kingdomId));
    }

    /**
     * @return list<object>
     */
    public function collectedForKingdom(int $kingdomId): array
    {
        $this->transactions->clear();
        $this->transactions->kingdom_id = $kingdomId;
        $this->transactions->orderBy('posted_on', OrderBy::ASC);
        $this->transactions->orderBy('id', OrderBy::ASC);

        return $this->transactions->collected();
    }
}
