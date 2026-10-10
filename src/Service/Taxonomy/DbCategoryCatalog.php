<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Taxonomy;

use Amtgard\Denarius\Domain\Taxonomy\CategoryCatalog;
use Amtgard\Denarius\Domain\Taxonomy\CategorySensitivity;
use Amtgard\Denarius\Domain\Taxonomy\CategorySnapshot;
use Amtgard\Denarius\Domain\Taxonomy\TaxonomyCategoryDisplay;
use Amtgard\Denarius\Domain\Taxonomy\TransactionFlow;
use Amtgard\Denarius\Utilities\Log\DenariusLog;
use PDO;

/** MariaDB-backed {@see CategoryCatalog}. */
final class DbCategoryCatalog implements CategoryCatalog
{
    /** @var array<int, CategorySnapshot> */
    private array $byId = [];

    /** @var array<string, int> */
    private array $currentLineage = [];

    private int $nextId = 1;

    public function __construct(private readonly PDO $pdo)
    {
        DenariusLog::enter(__METHOD__);
        $this->reload();
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
        foreach ($this->currentLineage as $lineageKey => $id) {
            $row = $this->byId[$id];
            if (! $row->assignable) {
                continue;
            }
            if (preg_match('/^k(\d+)\./', $lineageKey, $m) && (int) $m[1] !== $kingdomId) {
                continue;
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

        return $row->permits($amountFlowHint) ? $amountFlowHint : $row->primaryFlow();
    }

    public function permitsFlow(int $categoryId, TransactionFlow $flow): bool
    {
        $row = $this->findById($categoryId);

        return $row !== null && $row->permits($flow);
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
        $now = (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM);
        $flowsJson = json_encode([$flow->value], JSON_THROW_ON_ERROR);
        $statement = $this->pdo->prepare(
            'INSERT INTO categories (lineage_key, label, flows_json, sensitivity, summary_parent_label, assignable, supersedes_id, created_at)
             VALUES (:lineage_key, :label, :flows_json, NULL, NULL, 1, NULL, :created_at)',
        );
        $statement->execute([
            'lineage_key' => $lineageKey,
            'label' => $label,
            'flows_json' => $flowsJson,
            'created_at' => $now,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO category_lineages (lineage_key, current_category_id) VALUES (?, ?)',
        )->execute([$lineageKey, $id]);
        $this->reload();

        return $id;
    }

    public function forkLabel(int $categoryId, string $newLabel): int
    {
        $existing = $this->findById($categoryId);
        if ($existing === null) {
            throw new \InvalidArgumentException('Unknown category id: ' . $categoryId);
        }
        $now = (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM);
        $flowsJson = json_encode(
            array_map(static fn (TransactionFlow $f): string => $f->value, $existing->flows),
            JSON_THROW_ON_ERROR,
        );
        $statement = $this->pdo->prepare(
            'INSERT INTO categories (lineage_key, label, flows_json, sensitivity, summary_parent_label, assignable, supersedes_id, created_at)
             VALUES (:lineage_key, :label, :flows_json, :sensitivity, :summary_parent_label, :assignable, :supersedes_id, :created_at)',
        );
        $statement->execute([
            'lineage_key' => $existing->lineageKey,
            'label' => $newLabel,
            'flows_json' => $flowsJson,
            'sensitivity' => $existing->sensitivity?->value,
            'summary_parent_label' => $existing->summaryParentLabel,
            'assignable' => $existing->assignable ? 1 : 0,
            'supersedes_id' => $categoryId,
            'created_at' => $now,
        ]);
        $newId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'UPDATE category_lineages SET current_category_id = ? WHERE lineage_key = ?',
        )->execute([$newId, $existing->lineageKey]);
        $this->reload();

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

    private function reload(): void
    {
        DenariusLog::trace(__METHOD__, function (): void {
            $this->byId = [];
            $this->currentLineage = [];
            $this->nextId = 1;
            $rows = $this->pdo->query('SELECT id, lineage_key, label, flows_json, sensitivity, summary_parent_label, assignable FROM categories');
            if ($rows === false) {
                return;
            }
            while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
                if (! is_array($row)) {
                    continue;
                }
                $flows = [];
                $decoded = json_decode((string) ($row['flows_json'] ?? '[]'), true);
                if (is_array($decoded)) {
                    foreach ($decoded as $flowValue) {
                        if (is_string($flowValue)) {
                            $flows[] = TransactionFlow::fromStored($flowValue);
                        }
                    }
                }
                $id = (int) $row['id'];
                $sensitivity = isset($row['sensitivity']) && is_string($row['sensitivity']) && $row['sensitivity'] !== ''
                    ? CategorySensitivity::fromStored($row['sensitivity'])
                    : null;
                $this->byId[$id] = new CategorySnapshot(
                    $id,
                    (string) $row['lineage_key'],
                    (string) $row['label'],
                    $flows,
                    $sensitivity,
                    isset($row['summary_parent_label']) ? (string) $row['summary_parent_label'] : null,
                    ((int) ($row['assignable'] ?? 1)) === 1,
                );
                $this->nextId = max($this->nextId, $id + 1);
            }
            $lineages = $this->pdo->query('SELECT lineage_key, current_category_id FROM category_lineages');
            if ($lineages === false) {
                return;
            }
            while ($row = $lineages->fetch(PDO::FETCH_ASSOC)) {
                if (! is_array($row)) {
                    continue;
                }
                $this->currentLineage[(string) $row['lineage_key']] = (int) $row['current_category_id'];
            }
        });
    }
}
