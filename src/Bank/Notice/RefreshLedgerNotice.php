<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Bank\Notice;

use Amtgard\Denarius\Bank\ProviderNotice;
use Amtgard\Denarius\Contract\KingdomRefreshQueue;
use Amtgard\Denarius\Record\KingdomRecord;

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
