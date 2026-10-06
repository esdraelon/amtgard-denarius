<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication\Pipeline;

use Amtgard\Denarius\Domain\Statement\Publication\PublicationFlags;
use Amtgard\Denarius\Domain\Statement\Publication\PublicationHardRedactCopy;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Chain link: stub HARD-flagged lines for every publication path. */
final class HardRedactionStage implements PublicationStage
{
    public function process(PublicationEnvelope $envelope): PublicationEnvelope
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($envelope, $method): PublicationEnvelope {
            $redacted = [];
            foreach ($envelope->lines() as $line) {
                if (!PublicationFlags::parse($line->getPublicationFlags())->isHard()) {
                    $redacted[] = $line;
                    continue;
                }

                DenariusLog::debugBranch('publication_hard_redact', $method, [
                    'posted_on' => $line->getPostedOn(),
                ]);
                $redacted[] = PublicationCandidateLine::builder()
                    ->tellerTransactionId($line->getTellerTransactionId())
                    ->postedOn($line->getPostedOn())
                    ->amountCents(0)
                    ->category($line->getCategory())
                    ->description(PublicationHardRedactCopy::LINE_DESCRIPTION)
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
