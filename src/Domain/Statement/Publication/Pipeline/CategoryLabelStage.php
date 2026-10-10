<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication\Pipeline;

use Amtgard\Denarius\Domain\Statement\Presentation\DisplayMode;
use Amtgard\Denarius\Domain\Statement\Publication\PublicationFlags;
use Amtgard\Denarius\Domain\Taxonomy\CategoryCatalog;
use Amtgard\Denarius\Domain\Taxonomy\TaxonomyCatalog;
use Amtgard\Denarius\Domain\Taxonomy\TransactionFlow;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Chain link: map category ids to closed vocabulary labels for public output. */
final class CategoryLabelStage implements PublicationStage
{
    private const string HARD_LINEAGE = 'system.bank_verification';

    public function __construct(
        private readonly TaxonomyCatalog $catalog,
        private readonly CategoryCatalog $categories,
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

    private function labelLine(
        PublicationCandidateLine $line,
        DisplayMode $tier,
        string $method,
    ): PublicationCandidateLine {
        return DenariusLog::trace(__METHOD__, function () use ($line, $tier, $method): PublicationCandidateLine {
            $categoryId = $line->getCategoryId();
            $lineage = $this->categories->lineageKeyForId($categoryId);
            if (PublicationFlags::parse($line->getPublicationFlags())->isHard()) {
                $lineage = self::HARD_LINEAGE;
                $categoryId = $this->categories->currentIdForLineageKey($lineage);
            }
            $flow = $this->categories->flowFor($categoryId, TransactionFlow::defaultFromSignedCents($line->getAmountCents()));
            if (preg_match('/^k\d+\./', $lineage)) {
                $label = $this->categories->labelFor($categoryId);

                return $this->buildLabeledLine($line, $label, $flow);
            }
            $resolved = $this->catalog->resolveSlug($lineage);
            if (! $this->catalog->hasSlug($resolved)) {
                DenariusLog::debugBranch('publication_category_unknown_slug', $method, [
                    'lineage' => $lineage,
                ]);
                $resolved = 'uncategorized';
            }
            $flow = $this->catalog->flowForSlug($resolved, $line->getAmountCents());
            $label = $this->catalog->publicLabel($resolved, $tier);

            return $this->buildLabeledLine($line, $label, $flow);
        });
    }

    private function buildLabeledLine(
        PublicationCandidateLine $line,
        string $label,
        TransactionFlow $flow,
    ): PublicationCandidateLine {
        return DenariusLog::trace(__METHOD__, function () use ($line, $label, $flow): PublicationCandidateLine {
            return PublicationCandidateLine::builder()
                ->tellerTransactionId($line->getTellerTransactionId())
                ->postedOn($line->getPostedOn())
                ->amountCents($line->getAmountCents())
                ->categoryId($line->getCategoryId())
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
