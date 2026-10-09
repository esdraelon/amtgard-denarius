<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Ledger;

use Amtgard\Denarius\Domain\Statement\Publication\TransactionRecordRebuilder;
use Amtgard\Denarius\Domain\Taxonomy\CategorySource;
use Amtgard\Denarius\Domain\Taxonomy\Categorization\CategoryDecision;
use Amtgard\Denarius\Domain\Taxonomy\Categorization\TransactionCategorizer;
use Amtgard\Denarius\Domain\Taxonomy\TaxonomyCatalog;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Persistence\Repository\Kingdom\KingdomRepositoryInterface;
use Amtgard\Denarius\Persistence\Repository\Transaction\TransactionRepositoryInterface;
use Amtgard\Denarius\Service\Month\MonthInvalidator;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Facade: kingdom-scoped recategorize pass for stale or uncategorized rows. */
final class TransactionRecategorizer
{
    public function __construct(
        private readonly KingdomRepositoryInterface $kingdoms,
        private readonly TransactionRepositoryInterface $transactions,
        private readonly TransactionCategorizer $categorizer,
        private readonly LedgerProviderIdResolver $providerIds,
        private readonly TaxonomyCatalog $catalog,
        private readonly MonthInvalidator $months,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function recategorizeAll(): void
    {
        DenariusLog::trace(__METHOD__, function (): mixed {
            foreach ($this->kingdoms->all() as $kingdom) {
                if ($kingdom->getId() === null) {
                    continue;
                }
                $this->recategorizeKingdom($kingdom);
            }

            return null;
        });
    }

    public function recategorizeKingdomByOrkId(int $orkKingdomId): int
    {
        return DenariusLog::trace(__METHOD__, function () use ($orkKingdomId): int {
            $kingdom = $this->kingdoms->findByOrkId($orkKingdomId);
            if ($kingdom === null || $kingdom->getId() === null) {
                return 0;
            }

            return $this->recategorizeKingdom($kingdom);
        });
    }

    public function recategorizeKingdom(KingdomRecord $kingdom): int
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdom): int {
            $kingdomId = (int) $kingdom->getId();
            $catalogVersion = $this->catalog->taxonomyVersion();
            $updated = 0;
            foreach ($this->transactions->forKingdom($kingdomId) as $row) {
                if (! $this->needsRecategorize($row, $catalogVersion)) {
                    continue;
                }
                if ($this->applyRecategorizeRow($kingdom, $row)) {
                    ++$updated;
                }
            }
            if ($updated > 0) {
                $this->months->forget($kingdomId);
            }
            DenariusLog::infoBranch('transaction_recategorize_completed', self::class . '::recategorizeKingdom', [
                'kingdom_id' => $kingdomId,
                'updated_rows' => $updated,
            ]);

            return $updated;
        });
    }

    public function recategorizeKingdomAfterPatternChange(KingdomRecord $kingdom): int
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdom): int {
            $kingdomId = (int) $kingdom->getId();
            $updated = 0;
            foreach ($this->transactions->forKingdom($kingdomId) as $row) {
                if (CategorySource::fromStored($row->getCategorySource()) === CategorySource::Manager) {
                    continue;
                }
                if ($this->applyRecategorizeRow($kingdom, $row)) {
                    ++$updated;
                }
            }
            if ($updated > 0) {
                $this->months->forget($kingdomId);
            }
            DenariusLog::infoBranch('transaction_recategorize_completed', self::class . '::recategorizeKingdomAfterPatternChange', [
                'kingdom_id' => $kingdomId,
                'updated_rows' => $updated,
            ]);

            return $updated;
        });
    }

    private function applyRecategorizeRow(KingdomRecord $kingdom, TransactionRecord $row): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdom, $row): bool {
            $kingdomId = (int) $kingdom->getId();
            $providerId = $this->providerIds->forKingdom($kingdom);
            $before = $row->getCategory() . '|' . $row->getCategorySource() . '|' . ($row->getCategoryRuleId() ?? '');
            $decision = $this->categorizer->decide($providerId, $row, $row, $kingdomId);
            $merged = $this->mergeDecision($row, $decision);
            $after = $merged->getCategory() . '|' . $merged->getCategorySource() . '|' . ($merged->getCategoryRuleId() ?? '');
            if ($before === $after) {
                return false;
            }
            $this->transactions->upsert($merged);

            return true;
        });
    }

    private function needsRecategorize(TransactionRecord $row, string $catalogVersion): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($row, $catalogVersion): bool {
            if (CategorySource::fromStored($row->getCategorySource()) === CategorySource::Manager) {
                return false;
            }
            if ($row->getCategory() === 'uncategorized') {
                return true;
            }

            return $row->getTaxonomyVersion() !== $catalogVersion;
        });
    }

    private function mergeDecision(TransactionRecord $row, CategoryDecision $decision): TransactionRecord
    {
        return DenariusLog::trace(__METHOD__, function () use ($row, $decision): TransactionRecord {
            return TransactionRecordRebuilder::from($row)
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
