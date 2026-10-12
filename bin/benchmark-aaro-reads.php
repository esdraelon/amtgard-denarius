<?php

declare(strict_types=1);

use Amtgard\Denarius\Benchmark\BenchmarkFixtureSeeder;
use Amtgard\Denarius\Benchmark\BenchmarkRuntime;
use Amtgard\Denarius\Benchmark\CategoryCatalogReloadBenchmark;
use Amtgard\Denarius\Benchmark\TransactionReadBenchmark;

require dirname(__DIR__) . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$options = getopt('', ['kingdom-id:', 'seed:', 'iterations:', 'purge-only', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, <<<HELP
Usage: php bin/benchmark-aaro-reads.php [--kingdom-id=1] [--seed=5000] [--iterations=7] [--purge-only]

Requires APP_ENV and DB_* (same as app). Seeds rows with teller_transaction_id bench-aaro-*.

HELP);
    exit(0);
}

$kingdomId = isset($options['kingdom-id']) ? (int) $options['kingdom-id'] : 1;
$seedTarget = isset($options['seed']) ? (int) $options['seed'] : 5000;
$iterations = isset($options['iterations']) ? (int) $options['iterations'] : 7;
$purgeOnly = isset($options['purge-only']);

$pdo = BenchmarkRuntime::boot();
$seeder = new BenchmarkFixtureSeeder($pdo);
$kingdomId = $seeder->ensureKingdomId($kingdomId);

if ($purgeOnly) {
    $seeder->purgeBenchRows();
    fwrite(STDOUT, "Purged bench-aaro-* rows for kingdom {$kingdomId}.\n");
    exit(0);
}

$sampleTeller = $seeder->seedTransactions($kingdomId, $seedTarget);
$reads = (new TransactionReadBenchmark($pdo))->run($kingdomId, $iterations, $sampleTeller);
$catalogMs = (new CategoryCatalogReloadBenchmark($pdo))->medianReloadMs(max(3, min($iterations, 5)));

$branch = trim((string) shell_exec('git rev-parse --abbrev-ref HEAD 2>/dev/null')) ?: 'unknown';
$commit = trim((string) shell_exec('git rev-parse --short HEAD 2>/dev/null')) ?: 'unknown';

fwrite(STDOUT, json_encode([
    'branch' => $branch,
    'commit' => $commit,
    'kingdom_id' => $kingdomId,
    'seed_target' => $seedTarget,
    'iterations' => $iterations,
    'sample_teller_id' => $sampleTeller,
    'transaction_reads' => $reads,
    'category_catalog_reload_median_ms' => $catalogMs,
], JSON_PRETTY_PRINT) . "\n");

$ratio = $reads['pdo_list']['p95_ms'] > 0
    ? $reads['aaro_list']['p95_ms'] / $reads['pdo_list']['p95_ms']
    : 0.0;
fwrite(STDOUT, sprintf(
    "Summary: PDO list p50=%.2f p95=%.2f (%d rows) | AARO list p50=%.2f p95=%.2f (%d rows) | p95 ratio=%.2f | catalog reload p50=%.2f ms\n",
    $reads['pdo_list']['median_ms'],
    $reads['pdo_list']['p95_ms'],
    $reads['pdo_list']['row_count'],
    $reads['aaro_list']['median_ms'],
    $reads['aaro_list']['p95_ms'],
    $reads['aaro_list']['row_count'],
    $ratio,
    $catalogMs,
));

exit(0);
