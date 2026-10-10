<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Ledger;

use Amtgard\Denarius\Domain\Statement\Publication\TransactionRecordRebuilder;
use Amtgard\Denarius\Domain\Taxonomy\CategorySource;
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
            $kingdomId = $kingdom->getId();
            $decision = $this->categorizer->decide(
                $providerId,
                $incoming,
                $existing,
                $kingdomId !== null ? (int) $kingdomId : null,
            );

            return $this->mergeDecision($incoming, $decision);
        });
    }

    private function shouldKeepExistingCategory(TransactionRecord $incoming, ?TransactionRecord $existing): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($incoming, $existing): bool {
            if ($existing === null) {
                return false;
            }
            if (CategorySource::fromStored($existing->getCategorySource()) === CategorySource::Manager) {
                return true;
            }

            return $incoming->getDescription() === $existing->getDescription();
        });
    }

    private function copyExistingCategory(TransactionRecord $incoming, TransactionRecord $existing): TransactionRecord
    {
        return DenariusLog::trace(__METHOD__, function () use ($incoming, $existing): TransactionRecord {
            return TransactionRecordRebuilder::from($incoming)
                ->categoryId($existing->getCategoryId())
                ->categorySource($existing->getCategorySource())
                ->categoryRuleId($existing->getCategoryRuleId())
                ->categoryConfidence($existing->getCategoryConfidence())
                ->taxonomyVersion($existing->getTaxonomyVersion())
                ->build();
        });
    }

    private function mergeDecision(TransactionRecord $incoming, CategoryDecision $decision): TransactionRecord
    {
        return DenariusLog::trace(__METHOD__, function () use ($incoming, $decision): TransactionRecord {
            return TransactionRecordRebuilder::from($incoming)
                ->categoryId($decision->categoryId)
                ->categorySource($decision->source->value)
                ->categoryRuleId($decision->ruleId)
                ->categoryConfidence($decision->confidence)
                ->taxonomyVersion($decision->taxonomyVersion)
                ->build();
        });
    }
}
