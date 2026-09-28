<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Notice\Impl;

use Amtgard\Denarius\Domain\Bank\Notice\LedgerNotice;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;

final class IgnoredLedgerNotice implements LedgerNotice
{
    public function action(): string
    {
        return '';
    }

    public function apply(KingdomRecord $kingdom): void
    {
    }
}
