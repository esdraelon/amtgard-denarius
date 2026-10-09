<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Strategy: coarsens a balance delta relative to the last published balance bucket. */
final class BalancePullRounder
{
    public function coarsenBalanceCents(int $lastPublishedBalanceCents, int $providerBalanceCents, int $balanceQuantumCents): int
    {
        return DenariusLog::trace(__METHOD__, function () use ($lastPublishedBalanceCents, $providerBalanceCents, $balanceQuantumCents): int {
            if ($balanceQuantumCents <= 0) {
                return $providerBalanceCents;
            }
            $delta = $providerBalanceCents - $lastPublishedBalanceCents;
            $coarsenedDelta = (int) (round($delta / $balanceQuantumCents) * $balanceQuantumCents);

            return $lastPublishedBalanceCents + $coarsenedDelta;
        });
    }
}
