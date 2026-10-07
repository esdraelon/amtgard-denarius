<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication\Pattern;

use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\PublicationCandidateLine;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Strategy: SOFT keyword stub for professional-services payroll descriptors. */
final class ProfessionalServicesSoftPattern implements PublicationPattern
{
    public function id(): string
    {
        return DenariusLog::trace(__METHOD__, fn (): string => PublicationSoftPatternIds::PROFESSIONAL_SERVICES_KEYWORD);
    }

    public function version(): int
    {
        return DenariusLog::trace(__METHOD__, fn (): int => 1);
    }

    public function tier(): string
    {
        return DenariusLog::trace(__METHOD__, fn (): string => 'SOFT');
    }

    public function matches(PublicationCandidateLine $line): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($line): bool {
            $haystack = strtolower($line->getDescription() . ' ' . $line->getCounterparty());

            return str_contains($haystack, 'payroll')
                || str_contains($haystack, 'adp ')
                || str_contains($haystack, 'gusto')
                || str_contains($haystack, 'professional service');
        });
    }

    public function apply(PublicationCandidateLine $line): PublicationCandidateLine
    {
        return DenariusLog::trace(__METHOD__, function () use ($line): PublicationCandidateLine {
            return PublicationCandidateLine::builder()
                ->tellerTransactionId($line->getTellerTransactionId())
                ->postedOn($line->getPostedOn())
                ->amountCents($line->getAmountCents())
                ->category($line->getCategory())
                ->description('')
                ->counterparty('')
                ->status($line->getStatus())
                ->accountName($line->getAccountName())
                ->publishedAt($line->getPublishedAt())
                ->publishableAfter($line->getPublishableAfter())
                ->publicationFlags($line->getPublicationFlags())
                ->build();
        });
    }
}
