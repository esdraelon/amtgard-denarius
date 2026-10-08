<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Worker\Job\Impl;

use Amtgard\Denarius\Domain\Statement\MonthWindow;
use Amtgard\Denarius\Persistence\Repository\Kingdom\KingdomRepositoryInterface;
use Amtgard\Denarius\Service\Kingdom\KingdomPageQuery;
use Amtgard\Denarius\Service\Month\MonthCacheWriter;
use Amtgard\Denarius\Worker\Job\RefreshJob;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class MonthCacheRefreshJob implements RefreshJob
{
    public function __construct(
        private readonly KingdomRepositoryInterface $kingdoms,
        private readonly KingdomPageQuery $pages,
        private readonly MonthCacheWriter $cache,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function type(): string
    {
        return DenariusLog::trace(__METHOD__, function (): string {
            return 'month_cache';
        });
    }

    public function handle(array $payload): void
    {
        $method = __METHOD__;
        $kingdomId = (int) ($payload['kingdom_id'] ?? 0);
        $monthKey = (string) ($payload['month'] ?? '');

        DenariusLog::trace($method, function () use ($method, $kingdomId, $monthKey): mixed {
            if ($kingdomId <= 0 || !preg_match('/^(\d{4})-(\d{2})$/', $monthKey, $matches)) {
                DenariusLog::warnBranch('month_cache_refresh_failed', $method, [
                    'kingdom_id' => $kingdomId,
                    'month' => $monthKey,
                    'error' => 'Invalid payload',
                ]);

                return null;
            }
            $kingdom = $this->kingdoms->findById($kingdomId);
            if ($kingdom === null) {
                DenariusLog::warnBranch('month_cache_refresh_failed', $method, [
                    'kingdom_id' => $kingdomId,
                    'month' => $monthKey,
                    'error' => 'Kingdom not found',
                ]);

                return null;
            }
            $month = new MonthWindow((int) $matches[1], (int) $matches[2]);
            try {
                foreach (MonthCacheWriter::WARM_MODES as $mode) {
                    $statement = $this->pages->statementForMode($kingdom, $month, $mode);
                    $this->cache->write($kingdomId, $mode, $month, $statement);
                }
                DenariusLog::infoBranch('month_cache_refresh_complete', $method, [
                    'kingdom_id' => $kingdomId,
                    'month' => $monthKey,
                ]);
            } catch (\Throwable $e) {
                DenariusLog::warnBranch('month_cache_refresh_failed', $method, [
                    'kingdom_id' => $kingdomId,
                    'month' => $monthKey,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }

            return null;
        });
    }
}
