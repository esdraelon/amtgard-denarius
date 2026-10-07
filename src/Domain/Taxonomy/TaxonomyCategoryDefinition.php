<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy;

/** Value object: one row from taxonomy.json categories. */
final class TaxonomyCategoryDefinition
{
    /**
     * @param list<TransactionFlow> $flows
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $label,
        public readonly array $flows,
    ) {
    }

    public function permits(TransactionFlow $flow): bool
    {
        return in_array($flow, $this->flows, true);
    }
}
