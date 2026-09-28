<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Bank;

use Amtgard\Denarius\Record\KingdomRecord;

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
