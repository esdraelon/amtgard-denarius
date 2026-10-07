<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication\Pipeline;

use Amtgard\Denarius\Domain\Statement\Publication\BalancePullRounder;
use Amtgard\Denarius\Domain\Statement\Publication\BalanceQuantumCalculator;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Chain link: computes balance quantum and pull-rounds an optional provider balance. */
final class BalanceCoarseningStage implements PublicationStage
{
    public function __construct(
        private readonly BalanceQuantumCalculator $quantumCalculator = new BalanceQuantumCalculator(),
        private readonly BalancePullRounder $pullRounder = new BalancePullRounder(),
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function process(PublicationEnvelope $envelope): PublicationEnvelope
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($envelope, $method): PublicationEnvelope {
            $lineCount = count($envelope->lines());
            $balanceQuantum = $this->quantumCalculator->forLineCount($envelope->kingdom(), $lineCount);
            DenariusLog::debugBranch('publication_balance_quantum', $method, [
                'line_count' => $lineCount,
                'balance_quantum_cents' => $balanceQuantum,
            ]);

            $lastPublished = $envelope->lastPublishedBalanceCents();
            $providerBalance = $envelope->providerBalanceCents();
            if ($lastPublished === null || $providerBalance === null) {
                return $envelope->withBalanceQuantumCents($balanceQuantum);
            }

            $coarsened = $this->pullRounder->coarsenBalanceCents($lastPublished, $providerBalance, $balanceQuantum);
            if ($coarsened !== $providerBalance) {
                DenariusLog::debugBranch('publication_balance_coarsened', $method, [
                    'provider_cents' => $providerBalance,
                    'published_cents' => $coarsened,
                    'balance_quantum_cents' => $balanceQuantum,
                ]);
            }

            return $envelope
                ->withBalanceQuantumCents($balanceQuantum)
                ->withPublishedBalanceCents($coarsened);
        });
    }
}
