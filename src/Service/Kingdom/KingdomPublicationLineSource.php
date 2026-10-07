<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Kingdom;

use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\PublicationCandidateLine;
use Amtgard\Denarius\Persistence\Repository\Account\AccountRepositoryInterface;
use Amtgard\Denarius\Persistence\Repository\Transaction\TransactionRepositoryInterface;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Loads candidate lines from published accounts for pipeline input. */
final class KingdomPublicationLineSource
{
    public function __construct(
        private readonly TransactionRepositoryInterface $transactions,
        private readonly AccountRepositoryInterface $accounts,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    /**
     * @return list<PublicationCandidateLine>
     */
    public function candidates(KingdomRecord $kingdom): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdom): array {
            $names = $this->publishedAccountNames((int) $kingdom->getId());
            $lines = [];
            foreach ($this->transactions->forKingdom((int) $kingdom->getId()) as $transaction) {
                if (!isset($names[$transaction->getTellerAccountId()])) {
                    continue;
                }
                $lines[] = $this->candidate($transaction, $names[$transaction->getTellerAccountId()]);
            }

            return $lines;
        });
    }

    /**
     * @return array<string, string>
     */
    private function publishedAccountNames(int $kingdomId): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdomId): array {
            $names = [];
            foreach ($this->accounts->forKingdom($kingdomId) as $account) {
                if ($account->getPublished()) {
                    $names[$account->getTellerAccountId()] = $account->getName();
                }
            }

            return $names;
        });
    }

    private function candidate(TransactionRecord $transaction, string $accountName): PublicationCandidateLine
    {
        return DenariusLog::trace(__METHOD__, function () use ($transaction, $accountName): PublicationCandidateLine {
            return PublicationCandidateLine::builder()
                ->tellerTransactionId($transaction->getTellerTransactionId())
                ->postedOn($transaction->getPostedOn())
                ->amountCents($transaction->getAmountCents())
                ->category($transaction->getCategory())
                ->categorySource($transaction->getCategorySource())
                ->categoryConfidence($transaction->getCategoryConfidence())
                ->categorySuggested($transaction->getCategorySuggested())
                ->description($transaction->getDescription())
                ->counterparty($transaction->getCounterparty())
                ->status($transaction->getStatus())
                ->accountName($accountName)
                ->publishedAt($transaction->getPublishedAt())
                ->publishableAfter($transaction->getPublishableAfter())
                ->publicationFlags($transaction->getPublicationFlags())
                ->build();
        });
    }
}
