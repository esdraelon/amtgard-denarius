<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Month;

use Amtgard\Denarius\Domain\Statement\MonthWindow;
use Amtgard\Denarius\Persistence\Repository\Transaction\TransactionRepositoryInterface;
use Amtgard\Denarius\Utilities\Queue\Message\MessageQueue;
use Amtgard\Denarius\Worker\LedgerWorker;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Facade: enqueue month-cache warm jobs on the ledger worker queue. */
final class MonthCacheRefreshPublisher
{
    private const MAX_MONTHS = 24;

    public function __construct(
        private readonly MessageQueue $queue,
        private readonly TransactionRepositoryInterface $transactions,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function schedule(int $kingdomId, MonthWindow ...$months): void
    {
        $method = __METHOD__;

        DenariusLog::trace($method, function () use ($method, $kingdomId, $months): mixed {
            foreach ($this->targetMonths($kingdomId, ...$months) as $month) {
                $this->publish($kingdomId, $month);
                DenariusLog::debugBranch('month_cache_refresh_scheduled', $method, [
                    'kingdom_id' => $kingdomId,
                    'month' => $month->key(),
                ]);
            }

            return null;
        });
    }

    private function publish(int $kingdomId, MonthWindow $month): void
    {
        DenariusLog::trace(__METHOD__, function () use ($kingdomId, $month): mixed {
            $dedup = $kingdomId . ':' . $month->key();
            $payload = json_encode([
                'type' => 'month_cache',
                'kingdom_id' => $kingdomId,
                'month' => $month->key(),
            ], JSON_THROW_ON_ERROR);
            $this->queue->publish(LedgerWorker::QUEUE, $dedup, $payload);

            return null;
        });
    }

    /**
     * @return list<MonthWindow>
     */
    public function targetMonths(int $kingdomId, MonthWindow ...$months): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdomId, $months): array {
            return $months !== [] ? $months : $this->distinctPostedMonths($kingdomId);
        });
    }

    /**
     * @return list<MonthWindow>
     */
    private function distinctPostedMonths(int $kingdomId): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdomId): array {
            $keys = [];
            foreach ($this->transactions->forKingdom($kingdomId) as $row) {
                $posted = $row->getPostedOn();
                if ($posted === '') {
                    continue;
                }
                $key = substr($posted, 0, 7);
                if (preg_match('/^(\d{4})-(\d{2})$/', $key, $matches) !== 1) {
                    continue;
                }
                $keys[$key] = new MonthWindow((int) $matches[1], (int) $matches[2]);
            }
            if ($keys === []) {
                return [];
            }
            krsort($keys);

            return array_values(array_slice($keys, 0, self::MAX_MONTHS));
        });
    }
}
