<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication;

use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Prototype: rehydrates a transaction builder from persisted field values. */
final class TransactionRecordRebuilder
{
    public static function from(TransactionRecord $transaction): mixed
    {
        return DenariusLog::trace(__METHOD__, static function () use ($transaction): mixed {
            return TransactionRecord::builder()
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
                ->publicationFlags($transaction->getPublicationFlags());
        });
    }
}
