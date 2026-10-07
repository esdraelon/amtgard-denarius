<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Strategy: rounds signed cent amounts to a kingdom amount quantum. */
final class AmountQuantizer
{
    public function quantizeCents(int $amountCents, int $quantumCents): int
    {
        return DenariusLog::trace(__METHOD__, function () use ($amountCents, $quantumCents): int {
            if ($quantumCents <= 0) {
                return $amountCents;
            }
            if ($amountCents === 0) {
                return 0;
            }
            $sign = $amountCents < 0 ? -1 : 1;
            $abs = abs($amountCents);
            $rounded = (int) (round($abs / $quantumCents) * $quantumCents);
            if ($rounded === 0 && $abs > 0) {
                $rounded = $quantumCents;
            }

            return $sign * $rounded;
        });
    }
}
