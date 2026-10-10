<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Support;

use Amtgard\Denarius\Domain\Taxonomy\CategoryCatalog;
use Amtgard\Denarius\Domain\Taxonomy\CategoryCatalogSeeder;
use Amtgard\Denarius\Domain\Taxonomy\CategorySensitivity;
use Amtgard\Denarius\Domain\Taxonomy\CategorySnapshot;
use Amtgard\Denarius\Domain\Taxonomy\TaxonomyCatalog;
use Amtgard\Denarius\Domain\Taxonomy\TaxonomyCategoryDisplay;
use Amtgard\Denarius\Domain\Taxonomy\TransactionFlow;

/** In-memory {@see CategoryCatalog} for unit tests. */
final class MemoryCategoryCatalog implements CategoryCatalog
{
    /** @var array<int, CategorySnapshot> */
    private array $byId = [];

    /** @var array<string, int> lineage_key => current category id */
    private array $currentLineage = [];

    private int $nextId = 1;

    public static function seeded(TaxonomyCatalog $taxonomy, array $legacyCustomRows = []): self
    {
        $catalog = new self();
        $seed = CategoryCatalogSeeder::fromTaxonomyCatalog($taxonomy, $legacyCustomRows);
        foreach ($seed['snapshots'] as $snapshot) {
            $catalog->byId[$snapshot->id] = $snapshot;
            $catalog->nextId = max($catalog->nextId, $snapshot->id + 1);
        }
        $catalog->currentLineage = $seed['lineages'];

        return $catalog;
    }

    public function findById(int $categoryId): ?CategorySnapshot
    {
        return $this->byId[$categoryId] ?? null;
    }

    public function uncategorizedId(): int
    {
        return $this->currentIdForLineageKey('uncategorized');
    }

    public function currentIdForLineageKey(string $lineageKey): int
    {
        if (! isset($this->currentLineage[$lineageKey])) {
            throw new \InvalidArgumentException('Unknown category lineage: ' . $lineageKey);
        }

        return $this->currentLineage[$lineageKey];
    }

    public function lineageKeyForId(int $categoryId): string
    {
        $row = $this->findById($categoryId);
        if ($row === null) {
            throw new \InvalidArgumentException('Unknown category id: ' . $categoryId);
        }

        return $row->lineageKey;
    }

    public function search(string $query, ?TransactionFlow $flowFilter, int $kingdomId): array
    {
        $needle = strtolower(trim($query));
        $matches = [];
        $seenLineages = [];
        foreach ($this->currentLineage as $lineageKey => $id) {
            if (isset($seenLineages[$lineageKey])) {
                continue;
            }
            $seenLineages[$lineageKey] = true;
            $row = $this->byId[$id];
            if (! $row->assignable) {
                continue;
            }
            if (str_starts_with($lineageKey, 'k') && ! str_starts_with($lineageKey, 'k' . $kingdomId . '.')) {
                if (preg_match('/^k(\d+)\./', $lineageKey, $m) && (int) $m[1] !== $kingdomId) {
                    continue;
                }
            }
            $flow = $row->primaryFlow();
            if ($flowFilter !== null && ! $row->permits($flowFilter)) {
                continue;
            }
            if ($needle !== ''
                && ! str_contains(strtolower($row->label), $needle)
                && ! str_contains(strtolower($lineageKey), $needle)) {
                continue;
            }
            $matches[] = [
                'id' => $id,
                'label' => $row->label,
                'flow' => $flow->value,
                'assignable' => $row->assignable,
                'lineageKey' => $lineageKey,
            ];
        }
        usort($matches, static fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

        return $matches;
    }

    public function displayFor(int $categoryId, TransactionFlow $amountFlowHint): string
    {
        $row = $this->findById($categoryId);
        if ($row === null) {
            return TaxonomyCategoryDisplay::format($amountFlowHint, 'Uncategorized');
        }
        $flow = $row->permits($amountFlowHint) ? $amountFlowHint : $row->primaryFlow();

        return TaxonomyCategoryDisplay::format($flow, $row->label);
    }

    public function labelFor(int $categoryId): string
    {
        return $this->findById($categoryId)?->label ?? 'Uncategorized';
    }

    public function flowFor(int $categoryId, TransactionFlow $amountFlowHint): TransactionFlow
    {
        $row = $this->findById($categoryId);
        if ($row === null) {
            return $amountFlowHint;
        }
        if ($row->permits($amountFlowHint)) {
            return $amountFlowHint;
        }

        return $row->primaryFlow();
    }

    public function permitsFlow(int $categoryId, TransactionFlow $flow): bool
    {
        $row = $this->findById($categoryId);
        if ($row === null) {
            return false;
        }

        return $row->permits($flow);
    }

    public function isAssignable(int $categoryId): bool
    {
        return $this->findById($categoryId)?->assignable ?? false;
    }

    public function createWithLabel(TransactionFlow $flow, string $label, string $lineageKey): int
    {
        if (isset($this->currentLineage[$lineageKey])) {
            throw new \InvalidArgumentException('Lineage already exists: ' . $lineageKey);
        }
        $id = $this->nextId++;
        $this->byId[$id] = new CategorySnapshot(
            $id,
            $lineageKey,
            $label,
            [$flow],
            null,
            null,
            true,
        );
        $this->currentLineage[$lineageKey] = $id;

        return $id;
    }

    public function forkLabel(int $categoryId, string $newLabel): int
    {
        $existing = $this->findById($categoryId);
        if ($existing === null) {
            throw new \InvalidArgumentException('Unknown category id: ' . $categoryId);
        }
        $newId = $this->nextId++;
        $this->byId[$newId] = new CategorySnapshot(
            $newId,
            $existing->lineageKey,
            $newLabel,
            $existing->flows,
            $existing->sensitivity,
            $existing->summaryParentLabel,
            $existing->assignable,
        );
        $this->currentLineage[$existing->lineageKey] = $newId;

        return $newId;
    }

    public function resolveStoredSlugToId(string $legacySlug, int $kingdomId): int
    {
        $legacySlug = trim($legacySlug);
        if ($legacySlug === '' || $legacySlug === 'uncategorized') {
            return $this->uncategorizedId();
        }
        if (isset($this->currentLineage[$legacySlug])) {
            return $this->currentIdForLineageKey($legacySlug);
        }

        return $this->uncategorizedId();
    }

    /** Test helper: id for a seeded taxonomy lineage key. */
    public function idForLineage(string $lineageKey): int
    {
        return $this->currentIdForLineageKey($lineageKey);
    }
}
