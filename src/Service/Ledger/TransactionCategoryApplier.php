<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Ledger;

use Amtgard\Denarius\Domain\Statement\Publication\TransactionRecordRebuilder;
use Amtgard\Denarius\Domain\Taxonomy\Categorization\CategoryDecision;
use Amtgard\Denarius\Domain\Taxonomy\Categorization\TransactionCategorizer;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Collaborator: run categorizer during ingest before publication fields merge. */
final class TransactionCategoryApplier
{
    public function __construct(
        private readonly TransactionCategorizer $categorizer,
        private readonly LedgerProviderIdResolver $providerIds,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function apply(
        KingdomRecord $kingdom,
        TransactionRecord $incoming,
        ?TransactionRecord $existing,
    ): TransactionRecord {
        return DenariusLog::trace(__METHOD__, function () use ($kingdom, $incoming, $existing): TransactionRecord {
            if ($this->shouldKeepExistingCategory($incoming, $existing)) {
                return $this->copyExistingCategory($incoming, $existing);
            }

            $providerId = $this->providerIds->forKingdom($kingdom);
            $decision = $this->categorizer->decide($providerId, $incoming, $existing);

            return $this->mergeDecision($incoming, $decision);
        });
    }

    private function shouldKeepExistingCategory(TransactionRecord $incoming, ?TransactionRecord $existing): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($incoming, $existing): bool {
            if ($existing === null) {
                return false;
            }

            return $incoming->getDescription() === $existing->getDescription();
        });
    }

    private function copyExistingCategory(TransactionRecord $incoming, TransactionRecord $existing): TransactionRecord
    {
        return DenariusLog::trace(__METHOD__, function () use ($incoming, $existing): TransactionRecord {
            return TransactionRecordRebuilder::from($incoming)
                ->category($existing->getCategory())
                ->categorySource($existing->getCategorySource())
                ->categoryRuleId($existing->getCategoryRuleId())
                ->categoryConfidence($existing->getCategoryConfidence())
                ->categorySuggested($existing->getCategorySuggested())
                ->taxonomyVersion($existing->getTaxonomyVersion())
                ->build();
        });
    }

    private function mergeDecision(TransactionRecord $incoming, CategoryDecision $decision): TransactionRecord
    {
        return DenariusLog::trace(__METHOD__, function () use ($incoming, $decision): TransactionRecord {
            return TransactionRecordRebuilder::from($incoming)
                ->category($decision->category)
                ->categorySource($decision->source->value)
                ->categoryRuleId($decision->ruleId)
                ->categoryConfidence($decision->confidence)
                ->categorySuggested($decision->suggestedSlug)
                ->taxonomyVersion($decision->taxonomyVersion)
                ->build();
        });
    }
}
