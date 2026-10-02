<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Ledger;

use Amtgard\Denarius\Utilities\Queue\KingdomRefresh\KingdomRefreshQueue;
use Amtgard\Denarius\Persistence\Repository\Kingdom\KingdomRepositoryInterface;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class DailySweep
{
    public function __construct(
        private readonly KingdomRepositoryInterface $kingdoms,
        private readonly KingdomRefreshQueue $queue,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function enqueueConnected(): int
    {
        return DenariusLog::trace(__METHOD__, function (): int {
            $count = 0;
            foreach ($this->kingdoms->connected() as $kingdom) {
                $this->queue->publishLedger($kingdom->getOrkKingdomId());
                $count++;
            }

            return $count;
        });
    }
}
