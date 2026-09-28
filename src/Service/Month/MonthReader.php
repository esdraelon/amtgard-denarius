<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Month;

use Amtgard\Denarius\Domain\Statement\MonthStatement;
use Amtgard\Denarius\Domain\Statement\MonthWindow;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;

interface MonthReader
{
    public function statement(KingdomRecord $kingdom, MonthWindow $month): MonthStatement;
}
