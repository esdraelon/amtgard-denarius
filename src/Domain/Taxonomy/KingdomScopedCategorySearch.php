<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy;

use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Type-ahead search over shared taxonomy plus kingdom-local category lineages. */
final class KingdomScopedCategorySearch
{
    public function __construct(
        private readonly CategoryCatalog $categories,
    ) {
        DenariusLog::enter(__METHOD__);
    }

    /**
     * @return list<array{id: int, label: string, flow: string, lineageKey: string}>
     */
    public function search(KingdomRecord $kingdom, string $query, ?TransactionFlow $flowFilter): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdom, $query, $flowFilter): array {
            return $this->categories->search($query, $flowFilter, (int) $kingdom->getId());
        });
    }
}
