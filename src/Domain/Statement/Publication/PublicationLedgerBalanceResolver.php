<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication;

use Amtgard\Denarius\Domain\Statement\MonthWindow;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\PublicationCandidateLine;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Strategy: cumulative published-ledger balances at month open and month end. */
final class PublicationLedgerBalanceResolver
{
    /**
     * @param list<PublicationCandidateLine> $candidates
     */
    public function snapshotForMonth(MonthWindow $month, array $candidates): PublicationLedgerBalanceSnapshot
    {
        return DenariusLog::trace(__METHOD__, function () use ($month, $candidates): PublicationLedgerBalanceSnapshot {
            $monthStart = $month->startDate();
            $monthEnd = $month->endDate();
            $opening = 0;
            $providerEnd = 0;
            foreach ($candidates as $line) {
                if (! $this->isPublished($line)) {
                    continue;
                }
                $postedOn = $line->getPostedOn();
                if ($postedOn < $monthStart) {
                    $opening += $line->getAmountCents();
                }
                if ($postedOn <= $monthEnd) {
                    $providerEnd += $line->getAmountCents();
                }
            }

            return new PublicationLedgerBalanceSnapshot($opening, $providerEnd);
        });
    }

    private function isPublished(PublicationCandidateLine $line): bool
    {
        $publishedAt = $line->getPublishedAt();

        return $publishedAt !== null && $publishedAt !== '';
    }
}
