<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Benchmark;

use Amtgard\Denarius\Persistence\Repository\Category\CategoryRepositoryInterface;
use Amtgard\Denarius\Persistence\Repository\CategoryLineage\CategoryLineageRepositoryInterface;
use Amtgard\Denarius\Service\Taxonomy\DbCategoryCatalog;
use PDO;

/** Times {@see DbCategoryCatalog} constructor reload (PDO bulk read). */
final class CategoryCatalogReloadBenchmark
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    public function medianReloadMs(int $iterations): float
    {
        $timing = BenchmarkTiming::collect($iterations, function (): void {
            new DbCategoryCatalog($this->pdo, $this->noopCategories(), $this->noopLineages());
        });

        return $timing->medianMs();
    }

    private function noopCategories(): CategoryRepositoryInterface
    {
        return new class implements CategoryRepositoryInterface {
            public function insertRow(
                string $lineageKey,
                string $label,
                string $flowsJson,
                ?string $sensitivity,
                ?string $summaryParentLabel,
                bool $assignable,
                ?int $supersedesId,
                string $createdAt,
            ): int {
                return 0;
            }
        };
    }

    private function noopLineages(): CategoryLineageRepositoryInterface
    {
        return new class implements CategoryLineageRepositoryInterface {
            public function insertLineage(string $lineageKey, int $categoryId): void
            {
            }

            public function setCurrentCategoryId(string $lineageKey, int $categoryId): void
            {
            }
        };
    }
}
