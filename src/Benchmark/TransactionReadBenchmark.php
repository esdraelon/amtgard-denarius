<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Benchmark;

use Amtgard\Denarius\Persistence\Driver\Transaction\PdoTransactionReadDriver;
use Amtgard\Denarius\Persistence\Driver\Transaction\TransactionRecordMapper;
use Amtgard\Denarius\Persistence\Orm;
use Amtgard\Denarius\Persistence\Repository\Transaction\Impl\OrmTransactionRepository;
use PDO;

/** Compares PDO transaction read driver vs AARO list probe. */
final class TransactionReadBenchmark
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * @return array{
     *     pdo_list: array{median_ms: float, p95_ms: float, row_count: int},
     *     aaro_list: array{median_ms: float, p95_ms: float, row_count: int},
     *     pdo_find: array{median_ms: float},
     *     aaro_find: array{median_ms: float},
     * }
     */
    public function run(int $kingdomId, int $iterations, string $sampleTellerId): array
    {
        $pdoDriver = new PdoTransactionReadDriver($this->pdo, new TransactionRecordMapper());
        $aaroProbe = new AaroTransactionListProbe(
            Orm::repository(OrmTransactionRepository::class),
        );
        $orm = Orm::repository(OrmTransactionRepository::class);

        $pdoList = BenchmarkTiming::collect($iterations, static function () use ($pdoDriver, $kingdomId): void {
            $pdoDriver->listForKingdom($kingdomId);
        });
        $pdoRows = count($pdoDriver->listForKingdom($kingdomId));

        $aaroList = BenchmarkTiming::collect($iterations, static function () use ($aaroProbe, $kingdomId): void {
            $aaroProbe->collectedForKingdom($kingdomId);
        });
        $aaroRows = $aaroProbe->countForKingdom($kingdomId);

        $pdoFind = BenchmarkTiming::collect($iterations, static function () use ($pdoDriver, $sampleTellerId): void {
            $pdoDriver->findByTellerTransactionId($sampleTellerId);
        });

        $aaroFind = BenchmarkTiming::collect($iterations, static function () use ($orm, $sampleTellerId): void {
            $orm->findByTellerTransactionId($sampleTellerId);
        });

        return [
            'pdo_list' => [
                'median_ms' => $pdoList->medianMs(),
                'p95_ms' => $pdoList->p95Ms(),
                'row_count' => $pdoRows,
            ],
            'aaro_list' => [
                'median_ms' => $aaroList->medianMs(),
                'p95_ms' => $aaroList->p95Ms(),
                'row_count' => $aaroRows,
            ],
            'pdo_find' => ['median_ms' => $pdoFind->medianMs()],
            'aaro_find' => ['median_ms' => $aaroFind->medianMs()],
        ];
    }
}
