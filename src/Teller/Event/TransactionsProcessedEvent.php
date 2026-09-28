<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Teller\Event;

use Amtgard\Denarius\Contract\KingdomRefreshQueue;
use Amtgard\Denarius\Record\KingdomRecord;

final class TransactionsProcessedEvent implements EnrollmentEvent
{
    public function __construct(private readonly KingdomRefreshQueue $queue)
    {
    }

    public function type(): string
    {
        return 'transactions.processed';
    }

    public function apply(KingdomRecord $kingdom): void
    {
        $this->queue->publishLedger($kingdom->getOrkKingdomId());
    }
}
