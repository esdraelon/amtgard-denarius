<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Queue\KingdomRefresh;

interface KingdomRefreshQueue
{
    public function publishLedger(int $orkKingdomId): void;
}
