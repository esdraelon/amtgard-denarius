<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Worker\Job;

use Amtgard\Denarius\Service\TransactionSynchronizer;

final class LedgerRefreshJob implements RefreshJob
{
    public function __construct(private readonly TransactionSynchronizer $synchronizer)
    {
    }

    public function type(): string
    {
        return 'ledger';
    }

    public function handle(array $payload): void
    {
        $this->synchronizer->sync($this->kingdomId($payload));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function kingdomId(array $payload): int
    {
        return (int) ($payload['orkKingdomId'] ?? 0);
    }
}
