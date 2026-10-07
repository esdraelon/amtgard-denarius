<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication\Pipeline;

use Amtgard\Denarius\Domain\Statement\Publication\BalanceQuantumCalculator;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Chain link: holistic leak check between quantized lines and coarsened balance delta. */
final class EnvelopeReviewStage implements PublicationStage
{
    public function __construct(
        private readonly BalanceQuantumCalculator $quantumCalculator = new BalanceQuantumCalculator(),
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function process(PublicationEnvelope $envelope): PublicationEnvelope
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($envelope, $method): PublicationEnvelope {
            $lastPublished = $envelope->lastPublishedBalanceCents();
            $publishedBalance = $envelope->publishedBalanceCents();
            if ($lastPublished === null || $publishedBalance === null) {
                return $envelope;
            }

            $lineSum = $envelope->quantizedLineCentsSum();
            $impliedNet = $publishedBalance - $lastPublished;
            $quantum = $envelope->balanceQuantumCents()
                ?? $this->quantumCalculator->forLineCount($envelope->kingdom(), count($envelope->lines()));
            $tolerance = max($quantum, 1);

            if (abs($lineSum - $impliedNet) > $tolerance) {
                DenariusLog::debugBranch('publication_envelope_leak', $method, [
                    'line_sum_cents' => $lineSum,
                    'implied_net_cents' => $impliedNet,
                    'balance_quantum_cents' => $quantum,
                ]);

                return $envelope->withPublishedBalanceCents(null);
            }

            DenariusLog::debugBranch('publication_envelope_ok', $method, [
                'line_sum_cents' => $lineSum,
                'implied_net_cents' => $impliedNet,
            ]);

            return $envelope;
        });
    }
}
