<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy;

/** Value object: provider category string mapped to a taxonomy slug. */
final class TaxonomyProviderHint
{
    /**
     * @param list<TransactionFlow> $flows
     */
    public function __construct(
        public readonly string $id,
        public readonly string $provider,
        public readonly string $hint,
        public readonly string $category,
        public readonly array $flows,
        public readonly int $confidence,
    ) {
    }
}
