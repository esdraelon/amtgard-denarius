<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy;

/** Value object: one shared keyword rule from keywords.json. */
final class TaxonomyKeywordRule
{
    /**
     * @param list<string> $fields
     * @param list<TransactionFlow> $flows
     * @param list<string> $anyOfTokens
     */
    public function __construct(
        public readonly string $id,
        public readonly string $category,
        public readonly array $fields,
        public readonly string $matchType,
        public readonly string $regexPattern,
        public readonly array $anyOfTokens,
        public readonly string $token,
        public readonly array $flows,
        public readonly int $confidence,
    ) {
    }
}
