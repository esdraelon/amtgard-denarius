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
        DenariusLog::trace(__METHOD__, function () use ($payload): mixed {
            $this->synchronizer->sync($this->kingdomId($payload));

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
