<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Queue;

interface KingdomRefreshQueue
{
    public function publishLedger(int $orkKingdomId): void;

    public function publishDirectory(): void;
}
