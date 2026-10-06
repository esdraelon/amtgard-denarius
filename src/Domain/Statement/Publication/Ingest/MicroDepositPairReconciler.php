<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication\Ingest;

use Amtgard\Denarius\Domain\Statement\Publication\PublicationFlags;
use Amtgard\Denarius\Domain\Statement\Publication\PublicationPlatformLimits;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Persistence\Repository\Transaction\TransactionRepositoryInterface;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Collaborator: marks micro-deposit credit clusters HARD within the pair window. */
final class MicroDepositPairReconciler
{
    public function __construct(
        private readonly TransactionRepositoryInterface $transactions,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function reconcileAccount(KingdomRecord $kingdom, string $tellerAccountId): void
    {
        $method = __METHOD__;

        DenariusLog::trace($method, function () use ($method, $kingdom, $tellerAccountId): void {
            $kingdomId = (int) $kingdom->getId();
            $windowDays = PublicationPlatformLimits::DEFAULT_PAIR_WINDOW_DAYS;
            $credits = [];
            foreach ($this->transactions->forKingdom($kingdomId) as $transaction) {
                if ($transaction->getTellerAccountId() !== $tellerAccountId) {
                    continue;
                }
                if (!$this->isMicroCredit($transaction)) {
                    continue;
                }
                $credits[] = $transaction;
            }
            if (count($credits) < 2) {
                return;
            }

            foreach ($credits as $anchor) {
                $peers = $this->peersInWindow($anchor, $credits, $windowDays);
                if (count($peers) < 2) {
                    continue;
                }
                foreach ($peers as $peer) {
                    $this->markHard($peer, $method);
                }
            }
        });
    }

    /**
     * @param list<TransactionRecord> $credits
     *
     * @return list<TransactionRecord>
     */
    private function peersInWindow(TransactionRecord $anchor, array $credits, int $windowDays): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($anchor, $credits, $windowDays): array {
            $anchorDate = new \DateTimeImmutable($anchor->getPostedOn());
            $start = $anchorDate->modify(sprintf('-%d days', $windowDays));
            $end = $anchorDate->modify(sprintf('+%d days', $windowDays));
            $peers = [];
            foreach ($credits as $credit) {
                $posted = new \DateTimeImmutable($credit->getPostedOn());
                if ($posted >= $start && $posted <= $end) {
                    $peers[] = $credit;
                }
            }

            return $peers;
        });
    }

    private function isMicroCredit(TransactionRecord $transaction): bool
    {
        return DenariusLog::trace(__METHOD__, static function () use ($transaction): bool {
            $cents = $transaction->getAmountCents();

            return $cents > 0 && $cents < 100;
        });
    }

    private function markHard(TransactionRecord $transaction, string $logMethod): void
    {
        DenariusLog::trace(__METHOD__, function () use ($transaction, $logMethod): void {
            $flags = PublicationFlags::parse($transaction->getPublicationFlags())
                ->withHardPattern(PublicationHardPatternIds::MICRO_DEPOSIT_PAIR);
            if (!PublicationFlags::parse($transaction->getPublicationFlags())->isHard()) {
                DenariusLog::debugBranch('publication_micro_pair_mark', $logMethod, [
                    'teller_transaction_id' => $transaction->getTellerTransactionId(),
                ]);
            }
            $updated = TransactionRecord::builder()
                ->id($transaction->getId())
                ->kingdomId($transaction->getKingdomId())
                ->tellerTransactionId($transaction->getTellerTransactionId())
                ->tellerAccountId($transaction->getTellerAccountId())
                ->postedOn($transaction->getPostedOn())
                ->amountCents($transaction->getAmountCents())
                ->category($transaction->getCategory())
                ->description($transaction->getDescription())
                ->counterparty($transaction->getCounterparty())
                ->status($transaction->getStatus())
                ->publishedAt($transaction->getPublishedAt())
                ->publishableAfter($transaction->getPublishableAfter())
                ->publicationFlags($flags->encode())
                ->build();
            $this->transactions->upsert($updated);
        });
    }
}
