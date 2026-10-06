<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Ledger;

use Amtgard\Denarius\Domain\Statement\Publication\PublicationEmbargoCalculator;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Persistence\Repository\Transaction\TransactionRepositoryInterface;
use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Optional\Optional;

/** Collaborator: attaches publication fields when ledger rows are ingested. */
final class TransactionPublicationApplier
{
    public function __construct(
        private readonly TransactionRepositoryInterface $transactions,
        private readonly PublicationEmbargoCalculator $embargo,
        private readonly \DateTimeImmutable $now,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function apply(KingdomRecord $kingdom, TransactionRecord $incoming, bool $backfillAmnesty): TransactionRecord
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdom, $incoming, $backfillAmnesty): TransactionRecord {
            $existing = $this->findExisting($incoming->getTellerTransactionId());
            $embargoDays = $kingdom->getEmbargoDays();
            $publishableAfter = $this->embargo->publishableAfter(
                $incoming->getPostedOn(),
                $embargoDays,
                $this->now,
                $backfillAmnesty,
            );

            $builder = TransactionRecord::builder()
                ->kingdomId($incoming->getKingdomId())
                ->tellerTransactionId($incoming->getTellerTransactionId())
                ->tellerAccountId($incoming->getTellerAccountId())
                ->postedOn($incoming->getPostedOn())
                ->amountCents($incoming->getAmountCents())
                ->category($incoming->getCategory())
                ->description($incoming->getDescription())
                ->counterparty($incoming->getCounterparty())
                ->status($incoming->getStatus())
                ->publishableAfter($publishableAfter);

            Optional::ofNullable($existing?->getId())->ifPresent(static fn (int $id) => $builder->id($id));
            Optional::ofNullable($existing?->getPublishedAt())->ifPresent(static fn (string $at) => $builder->publishedAt($at));
            Optional::ofNullable($existing?->getPublicationFlags())->ifPresent(static fn (string $flags) => $builder->publicationFlags($flags));

            return $builder->build();
        });
    }

    private function findExisting(string $tellerTransactionId): ?TransactionRecord
    {
        return DenariusLog::trace(__METHOD__, function () use ($tellerTransactionId): ?TransactionRecord {
            return $this->transactions->findByTellerTransactionId($tellerTransactionId);
        });
    }
}
