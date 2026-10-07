<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Ledger;

use Amtgard\Denarius\Domain\Statement\Publication\PublicationFlags;
use Amtgard\Denarius\Domain\Statement\Publication\TransactionRecordRebuilder;
use Amtgard\Denarius\Domain\Taxonomy\CategorySource;
use Amtgard\Denarius\Domain\Taxonomy\ReviewCategoryValidator;
use Amtgard\Denarius\Domain\Taxonomy\TaxonomyCatalog;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Persistence\Repository\Account\AccountRepositoryInterface;
use Amtgard\Denarius\Persistence\Repository\Transaction\TransactionRepositoryInterface;
use Amtgard\Denarius\Service\Month\MonthInvalidator;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Facade: manager publish, category override, and withhold actions on transaction rows. */
final class TransactionReviewService
{
    public function __construct(
        private readonly TransactionRepositoryInterface $transactions,
        private readonly AccountRepositoryInterface $accounts,
        private readonly MonthInvalidator $months,
        private readonly ReviewCategoryValidator $categoryValidator,
        private readonly TaxonomyCatalog $catalog,
        private readonly \DateTimeImmutable $now,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function update(
        KingdomRecord $kingdom,
        string $tellerTransactionId,
        string $category,
        bool $publish,
        bool $bulkCounterparty,
    ): void {
        $method = __METHOD__;

        DenariusLog::trace($method, function () use ($method, $kingdom, $tellerTransactionId, $category, $publish, $bulkCounterparty): mixed {
            $primary = $this->requireReviewable($kingdom, $tellerTransactionId);
            $slug = $this->categoryValidator->assertAssignable($category, $primary->getAmountCents());
            if ($publish) {
                $this->assertPublishableCategory($primary, $slug, $method);
            }
            $targets = $this->targetsForUpdate($kingdom, $primary, $slug, $bulkCounterparty);
            $touchedMonth = false;
            foreach ($targets as $row) {
                if ($this->applyCategory($row, $slug, $method)) {
                    $touchedMonth = true;
                }
            }
            if ($touchedMonth) {
                $this->months->forget((int) $kingdom->getId());
            }
            if ($publish) {
                $this->publish($kingdom, $tellerTransactionId);
            }

            return null;
        });
    }

    public function publish(KingdomRecord $kingdom, string $tellerTransactionId): void
    {
        $method = __METHOD__;

        DenariusLog::trace($method, function () use ($method, $kingdom, $tellerTransactionId): mixed {
            $row = $this->requireReviewable($kingdom, $tellerTransactionId);
            $this->assertPublishableCategory($row, $row->getCategory(), $method);
            if (!$this->embargoOpen($row)) {
                DenariusLog::debugBranch('transaction_review_rejected_embargo', $method, [
                    'teller_transaction_id' => $tellerTransactionId,
                ]);
                throw new \InvalidArgumentException('That transaction is still inside the embargo window.');
            }
            if ($this->isPublished($row)) {
                DenariusLog::debugBranch('transaction_review_already_published', $method, [
                    'teller_transaction_id' => $tellerTransactionId,
                ]);

                return null;
            }
            $stamp = $this->now->format('Y-m-d\TH:i:sP');
            $this->transactions->markPublished((int) $kingdom->getId(), $tellerTransactionId, $stamp);
            $this->months->forget((int) $kingdom->getId());
            DenariusLog::infoBranch('transaction_review_published', $method, [
                'teller_transaction_id' => $tellerTransactionId,
                'published_at' => $stamp,
            ]);

            return null;
        });
    }

    public function withhold(KingdomRecord $kingdom, string $tellerTransactionId): void
    {
        $method = __METHOD__;

        DenariusLog::trace($method, function () use ($method, $kingdom, $tellerTransactionId): mixed {
            $row = $this->requireReviewable($kingdom, $tellerTransactionId);
            if (!$this->isPublished($row)) {
                DenariusLog::debugBranch('transaction_review_not_published', $method, [
                    'teller_transaction_id' => $tellerTransactionId,
                ]);

                return null;
            }
            $this->transactions->markUnpublished((int) $kingdom->getId(), $tellerTransactionId);
            $this->months->forget((int) $kingdom->getId());
            DenariusLog::infoBranch('transaction_review_withheld', $method, [
                'teller_transaction_id' => $tellerTransactionId,
            ]);

            return null;
        });
    }

    /**
     * @return list<TransactionRecord>
     */
    private function targetsForUpdate(
        KingdomRecord $kingdom,
        TransactionRecord $primary,
        string $slug,
        bool $bulkCounterparty,
    ): array {
        return DenariusLog::trace(__METHOD__, function () use ($kingdom, $primary, $slug, $bulkCounterparty): array {
            if (!$bulkCounterparty) {
                return [$primary];
            }
            $month = substr($primary->getPostedOn(), 0, 7);
            $counterparty = $primary->getCounterparty();
            $targets = [];
            foreach ($this->transactions->forKingdom((int) $kingdom->getId()) as $row) {
                if (!$this->accountPublished((int) $kingdom->getId(), $row->getTellerAccountId())) {
                    continue;
                }
                if ($row->getCounterparty() !== $counterparty) {
                    continue;
                }
                if (substr($row->getPostedOn(), 0, 7) !== $month) {
                    continue;
                }
                try {
                    $this->categoryValidator->assertAssignable($slug, $row->getAmountCents());
                } catch (\InvalidArgumentException) {
                    continue;
                }
                $targets[] = $row;
            }

            return $targets;
        });
    }

    private function applyCategory(TransactionRecord $row, string $slug, string $method): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($row, $slug, $method): bool {
            if ($row->getCategory() === $slug) {
                return false;
            }
            $updated = TransactionRecordRebuilder::from($row)
                ->category($slug)
                ->categorySource(CategorySource::Manager->value)
                ->categoryRuleId(null)
                ->categoryConfidence(100)
                ->categorySuggested(null)
                ->taxonomyVersion($this->catalog->taxonomyVersion())
                ->build();
            $this->transactions->upsert($updated);
            DenariusLog::infoBranch('transaction_review_category_set', $method, [
                'teller_transaction_id' => $row->getTellerTransactionId(),
                'slug' => $slug,
            ]);

            return true;
        });
    }

    private function assertPublishableCategory(TransactionRecord $row, string $category, string $method): void
    {
        DenariusLog::trace(__METHOD__, function () use ($row, $category, $method): void {
            if ($category !== 'uncategorized') {
                return;
            }
            if ($this->isHard($row)) {
                return;
            }
            DenariusLog::debugBranch('transaction_review_rejected_uncategorized', $method, [
                'teller_transaction_id' => $row->getTellerTransactionId(),
            ]);
            throw new \InvalidArgumentException('Choose a category before publishing this transaction.');
        });
    }

    private function isHard(TransactionRecord $row): bool
    {
        return DenariusLog::trace(__METHOD__, fn (): bool => PublicationFlags::parse($row->getPublicationFlags())->isHard());
    }

    private function requireReviewable(KingdomRecord $kingdom, string $tellerTransactionId): TransactionRecord
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($method, $kingdom, $tellerTransactionId): TransactionRecord {
            $id = trim($tellerTransactionId);
            if ($id === '') {
                DenariusLog::debugBranch('transaction_review_rejected_missing', $method, []);
                throw new \InvalidArgumentException('Transaction id is required.');
            }
            $row = $this->transactions->findByTellerTransactionId($id);
            if ($row === null || $row->getKingdomId() !== (int) $kingdom->getId()) {
                DenariusLog::debugBranch('transaction_review_rejected_missing', $method, [
                    'teller_transaction_id' => $id,
                ]);
                throw new \InvalidArgumentException('That transaction was not found for this kingdom.');
            }
            if (!$this->accountPublished((int) $kingdom->getId(), $row->getTellerAccountId())) {
                DenariusLog::debugBranch('transaction_review_rejected_account', $method, [
                    'teller_transaction_id' => $id,
                ]);
                throw new \InvalidArgumentException('That transaction is not on a published account.');
            }

            return $row;
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

    private function isPublished(TransactionRecord $row): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($row): bool {
            $publishedAt = $row->getPublishedAt();

            return $publishedAt !== null && $publishedAt !== '';
        });
    }

    private function embargoOpen(TransactionRecord $row): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($row): bool {
            $after = $row->getPublishableAfter();
            if ($after === null || $after === '') {
                return true;
            }

            return $this->now >= new \DateTimeImmutable($after);
        });
    }
}
