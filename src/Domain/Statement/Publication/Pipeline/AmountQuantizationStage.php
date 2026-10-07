<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication\Pipeline;

use Amtgard\Denarius\Domain\Statement\Publication\AmountQuantizer;
use Amtgard\Denarius\Domain\Statement\Publication\PublicationSettingsValidator;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Chain link: quantizes surviving line amounts to the kingdom amount quantum. */
final class AmountQuantizationStage implements PublicationStage
{
    public function __construct(
        private readonly PublicationSettingsValidator $settings = new PublicationSettingsValidator(),
        private readonly AmountQuantizer $quantizer = new AmountQuantizer(),
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function process(PublicationEnvelope $envelope): PublicationEnvelope
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($envelope, $method): PublicationEnvelope {
            $quantum = $this->settings->clampAmountQuantumCents($envelope->kingdom()->getAmountQuantumCents());
            $quantized = [];
            foreach ($envelope->lines() as $line) {
                $before = $line->getAmountCents();
                $after = $this->quantizer->quantizeCents($before, $quantum);
                if ($after !== $before) {
                    DenariusLog::debugBranch('publication_amount_quantized', $method, [
                        'posted_on' => $line->getPostedOn(),
                        'before_cents' => $before,
                        'after_cents' => $after,
                        'quantum_cents' => $quantum,
                    ]);
                }
                $quantized[] = PublicationCandidateLine::builder()
                    ->tellerTransactionId($line->getTellerTransactionId())
                    ->postedOn($line->getPostedOn())
                    ->amountCents($after)
                    ->category($line->getCategory())
                    ->description($line->getDescription())
                    ->counterparty($line->getCounterparty())
                    ->status($line->getStatus())
                    ->accountName($line->getAccountName())
                    ->publishedAt($line->getPublishedAt())
                    ->publishableAfter($line->getPublishableAfter())
                    ->publicationFlags($line->getPublicationFlags())
                    ->build();
            }

            return $envelope->withLines($quantized);
        });
    }
}
