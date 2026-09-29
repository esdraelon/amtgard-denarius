<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Notice\Impl;

use Amtgard\Denarius\Domain\Bank\Notice\LedgerNotice;
use Amtgard\Denarius\Domain\Bank\Enrollment\ProviderNotice;
use Amtgard\Denarius\Utilities\Queue\KingdomRefresh\KingdomRefreshQueue;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class RefreshLedgerNotice implements LedgerNotice
{
    public function __construct(private readonly KingdomRefreshQueue $queue)
    {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function action(): string
    {
        return DenariusLog::trace(__METHOD__, function (): string {
            return ProviderNotice::REFRESH;
        });
    }

    public function apply(KingdomRecord $kingdom): void
    {
        DenariusLog::trace(__METHOD__, function () use ($kingdom): mixed {
            $this->queue->publishLedger($kingdom->getOrkKingdomId());

            return null;
        });
    }
}
