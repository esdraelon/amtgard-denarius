<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Month;

use Amtgard\Denarius\Domain\MonthStatement;
use Amtgard\Denarius\Domain\MonthWindow;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;

interface MonthReader
{
    public function statement(KingdomRecord $kingdom, MonthWindow $month): MonthStatement;
}
