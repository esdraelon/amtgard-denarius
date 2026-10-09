<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Ledger;

use Amtgard\Denarius\Domain\Statement\Publication\Ingest\TransactionHardRedactAnnotator;
use Amtgard\Denarius\Domain\Statement\Publication\PublicationEmbargoCalculator;
use Amtgard\Denarius\Domain\Statement\Publication\PublicationFlags;
use Amtgard\Denarius\Domain\Statement\Publication\TransactionRecordRebuilder;
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
        private readonly TransactionHardRedactAnnotator $hardRedact,
        private readonly \DateTimeImmutable $now,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function apply(KingdomRecord $kingdom, TransactionRecord $incoming, bool $backfillAmnesty): TransactionRecord
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdom, $incoming, $backfillAmnesty): TransactionRecord {
            $existing = $this->findExisting($incoming->getTellerTransactionId());
            if (PublicationFlags::parse($existing?->getPublicationFlags())->isManagerEmbargoWaived()) {
                DenariusLog::debugBranch('publication_embargo_waived', self::class . '::apply', [
                    'teller_transaction_id' => $incoming->getTellerTransactionId(),
                ]);
                $publishableAfter = $this->now->setTime(0, 0)->format('c');
            } else {
                $publishableAfter = $this->embargo->publishableAfter(
                    $incoming->getPostedOn(),
                    $kingdom->getEmbargoDays(),
                    $this->now,
                    $backfillAmnesty,
                );
            }

            $builder = TransactionRecordRebuilder::from($incoming)->publishableAfter($publishableAfter);

            Optional::ofNullable($existing?->getId())->ifPresent(static fn (int $id) => $builder->id($id));
            Optional::ofNullable($existing?->getPublishedAt())->ifPresent(static fn (string $at) => $builder->publishedAt($at));
            Optional::ofNullable($existing?->getPublicationFlags())->ifPresent(static fn (string $flags) => $builder->publicationFlags($flags));

            return $this->hardRedact->annotate($builder->build());
        });
    }

    private function findExisting(string $tellerTransactionId): ?TransactionRecord
    {
        return DenariusLog::trace(__METHOD__, function () use ($tellerTransactionId): ?TransactionRecord {
            return $this->transactions->findByTellerTransactionId($tellerTransactionId);
        });
    }
}
