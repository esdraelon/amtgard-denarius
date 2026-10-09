<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement;

use Amtgard\Denarius\Domain\Statement\Line\CategoryTotal;
use Amtgard\Denarius\Domain\Statement\Line\LedgerLine;
use Amtgard\Denarius\Domain\Statement\Presentation\DisplayMode;
use Amtgard\Denarius\Domain\Statement\Publication\StatementAbsenceReason;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class MonthStatement
{
    /**
     * @param list<LedgerLine|CategoryTotal> $rows
     */
    public function __construct(
        public readonly DisplayMode $mode,
        public readonly MonthWindow $month,
        public readonly array $rows,
        public readonly ?StatementAbsenceReason $absenceReason = null,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }
}
