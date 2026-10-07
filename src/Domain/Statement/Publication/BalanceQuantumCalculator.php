<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication;

use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Strategy: derives balance bucket size from kingdom settings and published line count. */
final class BalanceQuantumCalculator
{
    public function forLineCount(KingdomRecord $kingdom, int $publishedLineCount): int
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdom, $publishedLineCount): int {
            $floor = $kingdom->getBalanceQuantumFloorCents();
            $ceiling = $kingdom->getBalanceQuantumCeilingCents();
            $step = $kingdom->getBalanceQuantumStepCents();
            $extra = max(0, $publishedLineCount - 1);

            return max($floor, min($ceiling, $floor + $step * $extra));
        });
    }
}
