<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Ledger;

use Amtgard\Denarius\Domain\Statement\Line\Money;
use Amtgard\Denarius\Domain\Taxonomy\CategoryCatalog;
use Amtgard\Denarius\Domain\Taxonomy\CategorySource;
use Amtgard\Denarius\Domain\Taxonomy\Categorization\CategorizationInput;
use Amtgard\Denarius\Domain\Taxonomy\Categorization\KeywordRuleMatcher;
use Amtgard\Denarius\Domain\Taxonomy\DescriptionNormalizer;
use Amtgard\Denarius\Domain\Taxonomy\TaxonomyKeywordRule;
use Amtgard\Denarius\Domain\Taxonomy\TransactionFlow;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Persistence\Repository\Account\AccountRepositoryInterface;
use Amtgard\Denarius\Persistence\Repository\Transaction\TransactionRepositoryInterface;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Facade: preview draft kingdom patterns and apply them from the review queue wizard. */
final class KingdomPatternReviewWizard
{
    public function __construct(
        private readonly KingdomPatternService $patterns,
        private readonly TransactionReviewService $reviews,
        private readonly TransactionRepositoryInterface $transactions,
        private readonly AccountRepositoryInterface $accounts,
        private readonly KeywordRuleMatcher $keywordMatcher,
        private readonly DescriptionNormalizer $normalizer,
        private readonly TransactionRecategorizer $recategorizer,
        private readonly CategoryCatalog $categories,
    ) {
        DenariusLog::enter(__METHOD__);
    }

    /**
     * @param array<string, mixed> $body
     * @return list<array{tellerTransactionId: string, postedOn: string, description: string, counterparty: string, amount: string}>
     */
    public function previewMatches(KingdomRecord $kingdom, array $body): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdom, $body): array {
            $kingdomId = (int) $kingdom->getId();
            $draft = $this->patterns->draftKeywordRuleForPreview($kingdom, $body, 'draft.preview');
            $anchorCents = $this->patterns->anchorAmountCents($body);
            $anchorTransactionId = $this->anchorTransactionId($body);
            $matches = [];
            foreach ($this->transactions->forKingdom($kingdomId) as $row) {
                if (! $this->isPatternApplyCandidate($row, $anchorTransactionId)) {
                    continue;
                }
                if (! $this->accountPublished($kingdomId, $row->getTellerAccountId())) {
                    continue;
                }
                if (! $this->amountSignMatches($anchorCents, $row->getAmountCents())) {
                    continue;
                }
                if (! $this->draftMatchesRow($draft, $kingdomId, $row)) {
                    continue;
                }
                $matches[] = [
                    'tellerTransactionId' => $row->getTellerTransactionId(),
                    'postedOn' => $row->getPostedOn(),
                    'description' => $row->getDescription(),
                    'counterparty' => $row->getCounterparty(),
                    'amount' => Money::format($row->getAmountCents()),
                ];
            }

            return $matches;
        });
    }

    /**
     * @param array<string, mixed> $body
     * @param list<string> $applyTellerTransactionIds
     */
    public function complete(KingdomRecord $kingdom, array $body, array $applyTellerTransactionIds): void
    {
        DenariusLog::trace(__METHOD__, function () use ($kingdom, $body, $applyTellerTransactionIds): void {
            $category = $this->patterns->resolveCategoryForSave($kingdom, $body);
            $applySet = [];
            foreach ($applyTellerTransactionIds as $id) {
                if (! is_string($id) || $id === '') {
                    continue;
                }
                $this->assertTransactionMatchesDraft($kingdom, $body, $id);
                $applySet[$id] = true;
            }
            if ($applySet === []) {
                throw new \InvalidArgumentException('Select at least one matching transaction to categorize.');
            }
            $previewIds = array_map(
                static fn (array $row): string => $row['tellerTransactionId'],
                $this->previewMatches($kingdom, $body),
            );
            $skipRecategorize = $previewIds;
            foreach (array_keys($applySet) as $appliedId) {
                $skipRecategorize[] = $appliedId;
            }

            $this->patterns->persistNew($kingdom, $body, $category);
            foreach (array_keys($applySet) as $tellerTransactionId) {
                $this->reviews->update($kingdom, $tellerTransactionId, $category, false);
            }
            $this->recategorizer->recategorizeKingdomAfterPatternChange($kingdom, array_values(array_unique($skipRecategorize)));
        });
    }

    private function draftMatchesRow(
        TaxonomyKeywordRule $draft,
        int $kingdomId,
        TransactionRecord $row,
    ): bool {
        return DenariusLog::trace(__METHOD__, function () use ($draft, $kingdomId, $row): bool {
            $input = $this->categorizationInput($kingdomId, $row);
            $match = $this->keywordMatcher->matchRules([$draft], $input, CategorySource::KingdomRule);
            if ($match === null) {
                return false;
            }

            return true;
        });
    }

    private function amountSignMatches(?int $anchorCents, int $rowCents): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($anchorCents, $rowCents): bool {
            if ($anchorCents === null) {
                return true;
            }

            return ($anchorCents > 0) === ($rowCents > 0);
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

    /**
     * @param array<string, mixed> $body
     */
    private function anchorTransactionId(array $body): ?string
    {
        return DenariusLog::trace(__METHOD__, function () use ($body): ?string {
            $id = trim((string) ($body['pattern_anchor_transaction_id'] ?? ''));
            if ($id === '') {
                return null;
            }

            return $id;
        });
    }

    private function isPatternApplyCandidate(TransactionRecord $row, ?string $anchorTransactionId): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($row, $anchorTransactionId): bool {
            if ($anchorTransactionId !== null && $row->getTellerTransactionId() === $anchorTransactionId) {
                return ! str_starts_with($this->categories->lineageKeyForId($row->getCategoryId()), 'system.');
            }
            if (CategorySource::fromStored($row->getCategorySource()) === CategorySource::Manager) {
                return false;
            }
            if (str_starts_with($this->categories->lineageKeyForId($row->getCategoryId()), 'system.')) {
                return false;
            }

            return true;
        });
    }

    /**
     * @param array<string, mixed> $body
     */
    private function assertTransactionMatchesDraft(
        KingdomRecord $kingdom,
        array $body,
        string $tellerTransactionId,
    ): void {
        DenariusLog::trace(__METHOD__, function () use ($kingdom, $body, $tellerTransactionId): void {
            $kingdomId = (int) $kingdom->getId();
            $id = trim($tellerTransactionId);
            $row = $this->transactions->findByTellerTransactionId($id);
            if ($row === null || $row->getKingdomId() !== $kingdomId) {
                throw new \InvalidArgumentException('One of the selected transactions was not found for this kingdom.');
            }
            $anchorTransactionId = $this->anchorTransactionId($body);
            if (! $this->isPatternApplyCandidate($row, $anchorTransactionId)) {
                throw new \InvalidArgumentException('One of the selected transactions cannot be updated by this pattern.');
            }
            if (! $this->accountPublished($kingdomId, $row->getTellerAccountId())) {
                throw new \InvalidArgumentException('One of the selected transactions is not on a published account.');
            }
            $anchorCents = $this->patterns->anchorAmountCents($body);
            if (! $this->amountSignMatches($anchorCents, $row->getAmountCents())) {
                throw new \InvalidArgumentException('One of the selected transactions does not match this pattern\'s expense/income direction.');
            }
            $draft = $this->patterns->draftKeywordRuleForPreview($kingdom, $body, 'draft.apply');
            if (! $this->draftMatchesRow($draft, $kingdomId, $row)) {
                throw new \InvalidArgumentException('One of the selected transactions no longer matches this pattern.');
            }
        });
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
