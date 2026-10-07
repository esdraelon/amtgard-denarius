<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication\Pipeline;

use Amtgard\Denarius\Domain\Statement\Presentation\DisplayMode;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Chain link: shape line fields by kingdom disclosure tier before presenters. */
final class LineRedactionStage implements PublicationStage
{
    public function process(PublicationEnvelope $envelope): PublicationEnvelope
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($envelope, $method): PublicationEnvelope {
            if ($envelope->disclosureTier() !== DisplayMode::Redacted) {
                return $envelope;
            }

            $redacted = [];
            foreach ($envelope->lines() as $line) {
                DenariusLog::debugBranch('publication_line_redacted_tier', $method, [
                    'posted_on' => $line->getPostedOn(),
                ]);
                $redacted[] = PublicationCandidateLine::builder()
                    ->tellerTransactionId($line->getTellerTransactionId())
                    ->postedOn($line->getPostedOn())
                    ->amountCents($line->getAmountCents())
                    ->category($line->getCategory())
                    ->categoryFlow($line->getCategoryFlow())
                    ->description('')
                    ->counterparty('')
                    ->status($line->getStatus())
                    ->accountName($line->getAccountName())
                    ->publishedAt($line->getPublishedAt())
                    ->publishableAfter($line->getPublishableAfter())
                    ->publicationFlags($line->getPublicationFlags())
                    ->build();
            }

            return $envelope->withLines($redacted);
        });
    }
}
