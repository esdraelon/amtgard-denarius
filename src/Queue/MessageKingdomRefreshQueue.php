<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Queue;

use Amtgard\Denarius\Contract\KingdomRefreshQueue;
use Amtgard\Denarius\Contract\MessageQueue;
use Amtgard\Denarius\Worker\LedgerWorker;

final class MessageKingdomRefreshQueue implements KingdomRefreshQueue
{
    public function __construct(
        private readonly MessageQueue $queue,
    ) {
    }

    public function publishLedger(int $orkKingdomId): void
    {
        $this->queue->publish(
            LedgerWorker::QUEUE,
            'ledger:' . $orkKingdomId,
            json_encode(['type' => 'ledger', 'orkKingdomId' => $orkKingdomId], JSON_THROW_ON_ERROR),
        );
    }

    public function publishDirectory(): void
    {
        $this->queue->publish(
            LedgerWorker::QUEUE,
            'directory',
            json_encode(['type' => 'directory'], JSON_THROW_ON_ERROR),
        );
    }
}
