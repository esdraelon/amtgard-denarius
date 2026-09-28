<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Notice\Impl;

use Amtgard\Denarius\Domain\Bank\Notice\LedgerNotice;
use Amtgard\Denarius\Domain\Bank\Enrollment\ProviderNotice;
use Amtgard\Denarius\Utilities\Queue\KingdomRefresh\KingdomRefreshQueue;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;

final class RefreshLedgerNotice implements LedgerNotice
{
    public function __construct(private readonly KingdomRefreshQueue $queue)
    {
    }

    public function action(): string
    {
        return ProviderNotice::REFRESH;
    }

    public function apply(KingdomRecord $kingdom): void
    {
        $this->queue->publishLedger($kingdom->getOrkKingdomId());
    }
}
