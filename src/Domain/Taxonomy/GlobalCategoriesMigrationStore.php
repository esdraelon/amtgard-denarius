<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy;

/** Port: SQL access for global categories migration. */
interface GlobalCategoriesMigrationStore
{
    /**
     * @return list<array{kingdom_id: int|string, slug: string, label: string, flow: string}>
     */
    public function legacyCustomCategories(): array;

    /**
     * @return list<array{id: int|string, category: string, kingdom_id: int|string}>
     */
    public function transactionsForBackfill(): array;

    /**
     * @return list<array{id: int|string, category: string, kingdom_id: int|string}>
     */
    public function rulesForBackfill(): array;

    public function insertCategory(
        string $lineageKey,
        string $label,
        string $flowsJson,
        ?string $sensitivity,
        ?string $summaryParentLabel,
        int $assignable,
        ?int $supersedesId,
        string $createdAt,
    ): int;

    public function insertLineage(string $lineageKey, int $categoryId): void;

    public function setTransactionCategoryId(int|string $transactionId, int $categoryId): void;

    public function setRuleCategoryId(int|string $ruleId, int $categoryId): void;

    public function hasTable(string $name): bool;
}
