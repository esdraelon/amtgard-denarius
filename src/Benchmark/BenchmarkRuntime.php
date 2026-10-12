<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Benchmark;

use Amtgard\ActiveRecordOrm\Configuration\Repository\DatabaseConfiguration;
use Amtgard\ActiveRecordOrm\Configuration\Repository\MysqlPdoProvider;
use Amtgard\Denarius\Utilities\Log\BranchLogLevel;
use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Amtgard\Denarius\Utilities\Log\MethodLog;
use Amtgard\Denarius\Utilities\Log\QuietMethodLog;
use PDO;

/** Boots quiet logging and a shared MariaDB PDO for benchmarks. */
final class BenchmarkRuntime
{
    public static function boot(): PDO
    {
        if (! DenariusLog::installedQuietly()) {
            DenariusLog::install(new QuietMethodLog(new class implements MethodLog {
                public function trace(string $method, callable $body): mixed
                {
                    return $body();
                }

                public function enter(string $method): string
                {
                    return $method;
                }

                public function branch(BranchLogLevel $level, string $branch, string $method, array $context = []): void
                {
                }
            }));
        }

        return MysqlPdoProvider::fromConfiguration(DatabaseConfiguration::fromEnvironment())->getPdo();
    }
}
