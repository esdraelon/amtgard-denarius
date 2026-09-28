<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service;

use Amtgard\Denarius\Utilities\Queue\KingdomRefresh\KingdomRefreshQueue;
use Amtgard\Denarius\Persistence\Repository\Kingdom\KingdomRepositoryInterface;

final class DailySweep
{
    public function __construct(
        private readonly KingdomRepositoryInterface $kingdoms,
        private readonly KingdomRefreshQueue $queue,
    ) {
    }

    public function enqueueConnected(): int
    {
        $count = 0;
        foreach ($this->kingdoms->connected() as $kingdom) {
            $this->queue->publishLedger($kingdom->getOrkKingdomId());
            $count++;
        }

        return $count;
    }
}
