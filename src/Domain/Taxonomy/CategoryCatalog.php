<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy;

/** Read/write registry: category rows are immutable; label edits fork a new row and move the lineage pointer. */
interface CategoryCatalog
{
    public function findById(int $categoryId): ?CategorySnapshot;

    public function uncategorizedId(): int;

    public function currentIdForLineageKey(string $lineageKey): int;

    public function lineageKeyForId(int $categoryId): string;

    /**
     * @return list<array{id: int, label: string, flow: string, assignable: bool, lineageKey: string}>
     */
    public function search(string $query, ?TransactionFlow $flowFilter, int $kingdomId): array;

    public function displayFor(int $categoryId, TransactionFlow $amountFlowHint): string;

    public function labelFor(int $categoryId): string;

    public function flowFor(int $categoryId, TransactionFlow $amountFlowHint): TransactionFlow;

    public function permitsFlow(int $categoryId, TransactionFlow $flow): bool;

    public function isAssignable(int $categoryId): bool;

    /** New lineage (manager free-text). */
    public function createWithLabel(TransactionFlow $flow, string $label, string $lineageKey): int;

    /**
     * Label changed while assigning: fork a new category row; update lineage current pointer.
     * Existing references keep their original category id.
     */
    public function forkLabel(int $categoryId, string $newLabel): int;

    public function resolveStoredSlugToId(string $legacySlug, int $kingdomId): int;
}
