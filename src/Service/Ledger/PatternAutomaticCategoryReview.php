<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Ledger;

use Amtgard\Denarius\Domain\Statement\Line\Money;
use Amtgard\Denarius\Domain\Taxonomy\CategoryConfidence;
use Amtgard\Denarius\Domain\Taxonomy\CategoryCatalog;
use Amtgard\Denarius\Domain\Taxonomy\CategorySource;
use Amtgard\Denarius\Domain\Taxonomy\Categorization\CategorizationInput;
use Amtgard\Denarius\Domain\Taxonomy\Categorization\KingdomRuleMatcher;
use Amtgard\Denarius\Domain\Taxonomy\DescriptionNormalizer;
use Amtgard\Denarius\Domain\Taxonomy\TransactionFlow;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Persistence\Repository\Account\AccountRepositoryInterface;
use Amtgard\Denarius\Persistence\Repository\Transaction\TransactionRepositoryInterface;
use Amtgard\Denarius\Service\Month\MonthInvalidator;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Facade: kingdom pattern revisions for one review month and manager apply. */
final class PatternAutomaticCategoryReview
{
    public function __construct(
        private readonly TransactionRepositoryInterface $transactions,
        private readonly AccountRepositoryInterface $accounts,
        private readonly KingdomRuleMatcher $kingdomRules,
        private readonly DescriptionNormalizer $normalizer,
        private readonly CategoryCatalog $categories,
        private readonly KingdomCategoryAssigner $categoryAssigner,
        private readonly TransactionReviewService $reviews,
        private readonly MonthInvalidator $months,
    ) {
        DenariusLog::enter(__METHOD__);
    }

    /**
     * @return list<array{
     *     tellerTransactionId: string,
     *     postedOn: string,
     *     description: string,
     *     counterparty: string,
     *     amount: string,
     *     currentCategory: string,
     *     currentDisplay: string,
     *     suggestedCategory: string,
     *     suggestedDisplay: string
     * }>
     */
    public function candidatesForMonth(KingdomRecord $kingdom, string $monthKey): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdom, $monthKey): array {
            $kingdomId = (int) $kingdom->getId();
            $candidates = [];
            foreach ($this->transactions->forKingdom($kingdomId) as $row) {
                $revision = $this->revisionForRow($kingdomId, $monthKey, $row);
                if ($revision === null) {
                    continue;
                }
                $candidates[] = $revision;
            }
            usort($candidates, static fn (array $a, array $b): int => strcmp($b['postedOn'], $a['postedOn']));
            DenariusLog::debugBranch('pattern_automatic_review_loaded', self::class . '::candidatesForMonth', [
                'kingdom_id' => $kingdomId,
                'month' => $monthKey,
                'row_count' => count($candidates),
            ]);

            return $candidates;
        });
    }

    /**
     * @param list<string> $tellerTransactionIds
     */
    public function applySelected(KingdomRecord $kingdom, string $monthKey, array $tellerTransactionIds): void
    {
        DenariusLog::trace(__METHOD__, function () use ($kingdom, $monthKey, $tellerTransactionIds): void {
            if ($tellerTransactionIds === []) {
                throw new \InvalidArgumentException('Select at least one transaction to update.');
            }
            $allowed = [];
            foreach ($this->candidatesForMonth($kingdom, $monthKey) as $candidate) {
                $allowed[$candidate['tellerTransactionId']] = (int) $candidate['suggestedCategoryId'];
            }
            $applied = 0;
            foreach ($tellerTransactionIds as $id) {
                if (! is_string($id) || $id === '') {
                    continue;
                }
                if (! isset($allowed[$id])) {
                    throw new \InvalidArgumentException('One of the selected transactions is no longer eligible for pattern categorization.');
                }
                $this->reviews->update($kingdom, $id, $allowed[$id], false);
                ++$applied;
            }
            if ($applied === 0) {
                throw new \InvalidArgumentException('Select at least one transaction to update.');
            }
            $this->months->forget((int) $kingdom->getId());
            DenariusLog::infoBranch('pattern_automatic_review_applied', self::class . '::applySelected', [
                'kingdom_id' => (int) $kingdom->getId(),
                'month' => $monthKey,
                'applied_rows' => $applied,
            ]);
        });
    }

    /**
     * @return array{
     *     tellerTransactionId: string,
     *     postedOn: string,
     *     description: string,
     *     counterparty: string,
     *     amount: string,
     *     currentCategory: string,
     *     currentDisplay: string,
     *     suggestedCategory: string,
     *     suggestedDisplay: string,
     *     suggestedCategoryId: int
     * }|null
     */
    private function revisionForRow(int $kingdomId, string $monthKey, TransactionRecord $row): ?array
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdomId, $monthKey, $row): ?array {
            if ($monthKey !== '' && ! str_starts_with($row->getPostedOn(), $monthKey)) {
                return null;
            }
            if (! $this->accountPublished($kingdomId, $row->getTellerAccountId())) {
                return null;
            }
            if (CategorySource::fromStored($row->getCategorySource()) === CategorySource::Manager) {
                return null;
            }
            $lineage = $this->categories->lineageKeyForId($row->getCategoryId());
            if (str_starts_with($lineage, 'system.')) {
                return null;
            }
            $match = $this->kingdomRules->match($this->categorizationInput($kingdomId, $row));
            if ($match === null || $match->source !== CategorySource::KingdomRule) {
                return null;
            }
            if ($match->confidence < CategoryConfidence::AUTO_ACCEPT) {
                return null;
            }
            if ($match->slug === $lineage) {
                return null;
            }
            $suggestedId = $this->categories->currentIdForLineageKey($match->slug);

            return [
                'tellerTransactionId' => $row->getTellerTransactionId(),
                'postedOn' => $row->getPostedOn(),
                'description' => $row->getDescription(),
                'counterparty' => $row->getCounterparty(),
                'amount' => Money::format($row->getAmountCents()),
                'currentCategory' => $lineage,
                'currentDisplay' => $this->formatDisplay($row->getCategoryId(), $row->getAmountCents()),
                'suggestedCategory' => $match->slug,
                'suggestedDisplay' => $this->formatDisplay($suggestedId, $row->getAmountCents()),
                'suggestedCategoryId' => $suggestedId,
            ];
        });
    }

    private function categorizationInput(int $kingdomId, TransactionRecord $row): CategorizationInput
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdomId, $row): CategorizationInput {
            return CategorizationInput::builder()
                ->normalizedDescription($this->normalizer->normalize($row->getDescription()))
                ->normalizedCounterparty($this->normalizer->normalize($row->getCounterparty()))
                ->providerCategory((string) ($row->getProviderCategory() ?? ''))
                ->defaultFlow(TransactionFlow::defaultFromSignedCents($row->getAmountCents()))
                ->kingdomId($kingdomId)
                ->build();
        });
    }

    private function formatDisplay(int $categoryId, int $amountCents): string
    {
        return DenariusLog::trace(__METHOD__, fn (): string => $this->categoryAssigner->displayFor(
            $categoryId,
            TransactionFlow::defaultFromSignedCents($amountCents),
        ));
    }

    private function accountPublished(int $kingdomId, string $tellerAccountId): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdomId, $tellerAccountId): bool {
            foreach ($this->accounts->forKingdom($kingdomId) as $account) {
                if ($account->getTellerAccountId() === $tellerAccountId && $account->getPublished()) {
                    return true;
                }
            }

            return false;
        });
    }
}
