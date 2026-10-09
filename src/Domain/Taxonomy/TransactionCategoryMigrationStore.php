<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy;

/** Port: reads legacy categories and writes normalized rows during schema migration. */
interface TransactionCategoryMigrationStore
{
    /**
     * @return list<array{id: int|string, category: string}>
     */
    public function legacyRows(): array;

    public function writeNormalized(int|string $id, string $category, ?string $providerCategory, string $categorySource): void;
}
