<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Ledger;

use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Persistence\Repository\Account\AccountRepositoryInterface;
use Amtgard\Denarius\Persistence\Repository\Transaction\TransactionRepositoryInterface;
use Amtgard\Denarius\Service\Month\MonthInvalidator;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Facade: manager publish and withhold actions on transaction rows. */
final class TransactionReviewService
{
    public function __construct(
        private readonly TransactionRepositoryInterface $transactions,
        private readonly AccountRepositoryInterface $accounts,
        private readonly MonthInvalidator $months,
        private readonly \DateTimeImmutable $now,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function publish(KingdomRecord $kingdom, string $tellerTransactionId): void
    {
        $method = __METHOD__;

        DenariusLog::trace($method, function () use ($method, $kingdom, $tellerTransactionId): mixed {
            $row = $this->requireReviewable($kingdom, $tellerTransactionId);
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
