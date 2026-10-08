<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Worker\Job\Impl;

use Amtgard\Denarius\Worker\Job\RefreshJob;
use Amtgard\Denarius\Service\Ledger\TransactionSynchronizer;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class LedgerRefreshJob implements RefreshJob
{
    public function __construct(private readonly TransactionSynchronizer $synchronizer)
    {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function type(): string
    {
        return DenariusLog::trace(__METHOD__, function (): string {
            return 'ledger';
        });
    }

    public function handle(array $payload): void
    {
        $orkKingdomId = $this->kingdomId($payload);
        DenariusLog::trace(__METHOD__, function () use ($orkKingdomId): mixed {
            try {
                $this->synchronizer->sync($orkKingdomId);
            } catch (\Throwable $e) {
                DenariusLog::warnBranch('ledger_sync_failed', __METHOD__, [
                    'ork_kingdom_id' => $orkKingdomId,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }

            return null;
        });
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function kingdomId(array $payload): int
    {
        return DenariusLog::trace(__METHOD__, function () use ($payload): int {
            return (int) ($payload['orkKingdomId'] ?? 0);
        });
    }
}
