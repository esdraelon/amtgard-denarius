<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Month;

use Amtgard\Denarius\Domain\Statement\MonthWindow;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Facade: drop cached month blobs and schedule worker rebuilds. */
final class MonthInvalidator
{
    public function __construct(
        private readonly MonthCacheWriter $cache,
        private readonly MonthCacheRefreshPublisher $publisher,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function forget(int $kingdomId): void
    {
        DenariusLog::trace(__METHOD__, function () use ($kingdomId): mixed {
            $this->invalidate($kingdomId);

            return null;
        });
    }

    public function invalidate(int $kingdomId, MonthWindow ...$months): void
    {
        DenariusLog::trace(__METHOD__, function () use ($kingdomId, $months): mixed {
            $targets = $this->publisher->targetMonths($kingdomId, ...$months);
            foreach ($targets as $month) {
                $this->cache->deleteMonth($kingdomId, $month);
            }
            $this->publisher->schedule($kingdomId, ...$targets);

            return null;
        });
    }
}
