<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class GlobalCategoriesMigrator
{
    public function __construct(
        private readonly TaxonomyCatalog $catalog,
        private readonly GlobalCategoriesMigrationStore $store,
    ) {
    }

    public function migrate(): void
    {
        DenariusLog::trace(__METHOD__, function (): void {
            $now = (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM);
            $customRows = array_map(static fn (array $row): array => [
                'kingdom_id' => (int) $row['kingdom_id'],
                'slug' => (string) $row['slug'],
                'label' => (string) $row['label'],
                'flow' => (string) $row['flow'],
            ], $this->store->legacyCustomCategories());

            /** @var array<string, int> $lineageToId */
            $lineageToId = [];

            foreach ($this->catalog->allDefinitions() as $definition) {
                $lineageToId[$definition->slug] = $this->insertSnapshot(
                    $definition->slug,
                    $definition->label,
                    $definition->flows,
                    $definition->sensitivity,
                    $definition->summaryParentLabel,
                    ! str_starts_with($definition->slug, 'system.'),
                    $now,
                );
            }

            foreach ($customRows as $row) {
                $slug = $row['slug'];
                if (isset($lineageToId[$slug])) {
                    continue;
                }
                $lineageToId[$slug] = $this->insertSnapshot(
                    $slug,
                    $row['label'],
                    [TransactionFlow::fromStored($row['flow'])],
                    null,
                    null,
                    true,
                    $now,
                );
            }

            $uncategorizedId = $lineageToId['uncategorized'] ?? null;
            if ($uncategorizedId === null) {
                throw new \RuntimeException('Taxonomy pack is missing uncategorized.');
            }

            foreach ($this->store->transactionsForBackfill() as $row) {
                $slug = trim((string) ($row['category'] ?? ''));
                $kingdomId = (int) $row['kingdom_id'];
                $categoryId = $this->mapLegacySlug($slug, $kingdomId, $lineageToId, $uncategorizedId);
                $this->store->setTransactionCategoryId($row['id'], $categoryId);
            }

            foreach ($this->store->rulesForBackfill() as $row) {
                $slug = trim((string) ($row['category'] ?? ''));
                $kingdomId = (int) $row['kingdom_id'];
                $categoryId = $this->mapLegacySlug($slug, $kingdomId, $lineageToId, $uncategorizedId);
                $this->store->setRuleCategoryId($row['id'], $categoryId);
            }
        });
    }

    /**
     * @param array<string, int> $lineageToId
     */
    private function mapLegacySlug(string $slug, int $kingdomId, array $lineageToId, int $uncategorizedId): int
    {
        if ($slug === '' || $slug === 'uncategorized') {
            return $uncategorizedId;
        }
        $resolved = $this->catalog->resolveSlug($slug);
        if (isset($lineageToId[$resolved])) {
            return $lineageToId[$resolved];
        }
        if (isset($lineageToId[$slug])) {
            return $lineageToId[$slug];
        }

        return $uncategorizedId;
    }

    /**
     * @param list<TransactionFlow> $flows
     */
    private function insertSnapshot(
        string $lineageKey,
        string $label,
        array $flows,
        ?CategorySensitivity $sensitivity,
        ?string $summaryParentLabel,
        bool $assignable,
        string $createdAt,
    ): int {
        $flowsJson = json_encode(
            array_map(static fn (TransactionFlow $flow): string => $flow->value, $flows),
            JSON_THROW_ON_ERROR,
        );
        $id = $this->store->insertCategory(
            $lineageKey,
            $label,
            $flowsJson,
            $sensitivity?->value,
            $summaryParentLabel,
            $assignable ? 1 : 0,
            null,
            $createdAt,
        );
        $this->store->insertLineage($lineageKey, $id);

        return $id;
    }
}
