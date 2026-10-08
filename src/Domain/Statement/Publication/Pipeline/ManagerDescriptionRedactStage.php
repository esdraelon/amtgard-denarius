<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication\Pipeline;

use Amtgard\Denarius\Domain\Statement\Publication\PublicationFlags;
use Amtgard\Denarius\Domain\Statement\Publication\PublicationManagerRedactCopy;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Chain link: manager-selected description scrub before presenters. */
final class ManagerDescriptionRedactStage implements PublicationStage
{
    public function process(PublicationEnvelope $envelope): PublicationEnvelope
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($envelope, $method): PublicationEnvelope {
            $updated = [];
            foreach ($envelope->lines() as $line) {
                if (!PublicationFlags::parse($line->getPublicationFlags())->isManagerRedactDescription()) {
                    $updated[] = $line;
                    continue;
                }
                DenariusLog::debugBranch('publication_manager_description_redact', $method, [
                    'posted_on' => $line->getPostedOn(),
                ]);
                $updated[] = PublicationCandidateLine::builder()
                    ->tellerTransactionId($line->getTellerTransactionId())
                    ->postedOn($line->getPostedOn())
                    ->amountCents($line->getAmountCents())
                    ->category($line->getCategory())
                    ->description(PublicationManagerRedactCopy::LINE_DESCRIPTION)
                    ->counterparty('')
                    ->status($line->getStatus())
                    ->accountName($line->getAccountName())
                    ->publishedAt($line->getPublishedAt())
                    ->publishableAfter($line->getPublishableAfter())
                    ->publicationFlags($line->getPublicationFlags())
                    ->build();
            }

            return $envelope->withLines($updated);
        });
    }
}
