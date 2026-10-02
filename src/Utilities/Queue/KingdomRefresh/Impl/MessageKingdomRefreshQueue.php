<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Queue\KingdomRefresh\Impl;

use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Amtgard\Denarius\Utilities\Queue\KingdomRefresh\KingdomRefreshQueue;
use Amtgard\Denarius\Utilities\Queue\Message\MessageQueue;
use Amtgard\Denarius\Worker\LedgerWorker;

final class MessageKingdomRefreshQueue implements KingdomRefreshQueue
{
    public function __construct(
        private readonly MessageQueue $queue,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function publishLedger(int $orkKingdomId): void
    {
        DenariusLog::trace(__METHOD__, function () use ($orkKingdomId): mixed {
            $this->queue->publish(
                LedgerWorker::QUEUE,
                'ledger:' . $orkKingdomId,
                json_encode(['type' => 'ledger', 'orkKingdomId' => $orkKingdomId], JSON_THROW_ON_ERROR),
            );

            return null;
        });
    }
}
