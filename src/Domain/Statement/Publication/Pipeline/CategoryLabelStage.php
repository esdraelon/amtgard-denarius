<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication\Pipeline;

use Amtgard\Denarius\Domain\Statement\Presentation\DisplayMode;
use Amtgard\Denarius\Domain\Statement\Publication\PublicationFlags;
use Amtgard\Denarius\Domain\Taxonomy\TaxonomyCatalog;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Chain link: map category slugs to closed vocabulary labels for public output. */
final class CategoryLabelStage implements PublicationStage
{
    private const string HARD_CATEGORY = 'system.bank_verification';

    public function __construct(
        private readonly TaxonomyCatalog $catalog,
    ) {
        DenariusLog::enter(__METHOD__);
    }

    public function process(PublicationEnvelope $envelope): PublicationEnvelope
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($envelope, $method): PublicationEnvelope {
            $tier = $envelope->disclosureTier();
            $updated = [];
            foreach ($envelope->lines() as $line) {
                $updated[] = $this->labelLine($line, $tier, $method);
            }

            return $envelope->withLines($updated);
        });
    }

    private function labelLine(PublicationCandidateLine $line, DisplayMode $tier, string $method): PublicationCandidateLine
    {
        return DenariusLog::trace(__METHOD__, function () use ($line, $tier, $method): PublicationCandidateLine {
            $slug = $line->getCategory();
            if (PublicationFlags::parse($line->getPublicationFlags())->isHard()) {
                $slug = self::HARD_CATEGORY;
            }
            $resolved = $this->catalog->resolveSlug($slug);
            if (! $this->catalog->hasSlug($resolved)) {
                DenariusLog::debugBranch('publication_category_unknown_slug', $method, [
                    'slug' => $slug,
                ]);
                $resolved = 'uncategorized';
            }
            $flow = $this->catalog->flowForSlug($resolved, $line->getAmountCents());
            $label = $this->catalog->publicLabel($resolved, $tier);

            return PublicationCandidateLine::builder()
                ->tellerTransactionId($line->getTellerTransactionId())
                ->postedOn($line->getPostedOn())
                ->amountCents($line->getAmountCents())
                ->category($label)
                ->categoryFlow($flow->value)
                ->description($line->getDescription())
                ->counterparty($line->getCounterparty())
                ->status($line->getStatus())
                ->accountName($line->getAccountName())
                ->publishedAt($line->getPublishedAt())
                ->publishableAfter($line->getPublishableAfter())
                ->publicationFlags($line->getPublicationFlags())
                ->build();
        });
    }
}
