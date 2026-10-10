<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy;

use Phinx\Db\Adapter\AdapterInterface;

final class PhinxGlobalCategoriesMigrationStore implements GlobalCategoriesMigrationStore
{
    public function __construct(private readonly AdapterInterface $adapter)
    {
    }

    public function legacyCustomCategories(): array
    {
        if (! $this->hasTable('kingdom_custom_categories')) {
            return [];
        }

        /** @var list<array{kingdom_id: int|string, slug: string, label: string, flow: string}> */
        return $this->adapter->fetchAll('SELECT kingdom_id, slug, label, flow FROM kingdom_custom_categories');
    }

    public function transactionsForBackfill(): array
    {
        /** @var list<array{id: int|string, category: string, kingdom_id: int|string}> */
        return $this->adapter->fetchAll('SELECT id, category, kingdom_id FROM transactions');
    }

    public function rulesForBackfill(): array
    {
        /** @var list<array{id: int|string, category: string, kingdom_id: int|string}> */
        return $this->adapter->fetchAll('SELECT id, category, kingdom_id FROM kingdom_category_rules');
    }

    public function insertCategory(
        string $lineageKey,
        string $label,
        string $flowsJson,
        ?string $sensitivity,
        ?string $summaryParentLabel,
        int $assignable,
        ?int $supersedesId,
        string $createdAt,
    ): int {
        $this->adapter->execute(
            'INSERT INTO categories (lineage_key, label, flows_json, sensitivity, summary_parent_label, assignable, supersedes_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$lineageKey, $label, $flowsJson, $sensitivity, $summaryParentLabel, $assignable, $supersedesId, $createdAt],
        );

        return (int) $this->adapter->getConnection()->lastInsertId();
    }

    public function insertLineage(string $lineageKey, int $categoryId): void
    {
        $this->adapter->execute(
            'INSERT INTO category_lineages (lineage_key, current_category_id) VALUES (?, ?)',
            [$lineageKey, $categoryId],
        );
    }

    public function setTransactionCategoryId(int|string $transactionId, int $categoryId): void
    {
        $this->adapter->execute(
            'UPDATE transactions SET category_id = ? WHERE id = ?',
            [$categoryId, $transactionId],
        );
    }

    public function setRuleCategoryId(int|string $ruleId, int $categoryId): void
    {
        $this->adapter->execute(
            'UPDATE kingdom_category_rules SET category_id = ? WHERE id = ?',
            [$categoryId, $ruleId],
        );
    }

    public function hasTable(string $name): bool
    {
        return $this->adapter->hasTable($name);
    }
}
