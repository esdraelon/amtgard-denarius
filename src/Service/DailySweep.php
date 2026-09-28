<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service;

use Amtgard\Denarius\Contract\KingdomRefreshQueue;
use Amtgard\Denarius\Contract\KingdomStore;

final class DailySweep
{
    public function __construct(
        private readonly KingdomStore $kingdoms,
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
