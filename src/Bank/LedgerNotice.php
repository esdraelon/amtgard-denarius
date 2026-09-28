<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Bank;

use Amtgard\Denarius\Record\KingdomRecord;

interface LedgerNotice
{
    public function action(): string;

    public function apply(KingdomRecord $kingdom): void;
}
