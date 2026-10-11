<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Benchmark;

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
            new DbCategoryCatalog($this->pdo);
        });

        return $timing->medianMs();
    }
}
