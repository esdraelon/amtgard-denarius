<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Strategy: computes publishable_after from posted_on, embargo_days, and backfill amnesty. */
final class PublicationEmbargoCalculator
{
    public function publishableAfter(
        string $postedOn,
        int $embargoDays,
        \DateTimeImmutable $now,
        bool $backfillAmnesty,
    ): string {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($method, $postedOn, $embargoDays, $now, $backfillAmnesty): string {
            $days = (new PublicationSettingsValidator())->clampEmbargoDays($embargoDays);
            if ($backfillAmnesty && $this->qualifiesForBackfillAmnesty($postedOn, $days, $now)) {
                DenariusLog::debugBranch('embargo_backfill_amnesty', $method, [
                    'posted_on' => $postedOn,
                    'embargo_days' => $days,
                ]);

                return $now->setTime(0, 0)->format('c');
            }

            DenariusLog::debugBranch('embargo_standard', $method, [
                'posted_on' => $postedOn,
                'embargo_days' => $days,
            ]);

            return $this->standardPublishableAfter($postedOn, $days);
        });
    }

    private function qualifiesForBackfillAmnesty(string $postedOn, int $embargoDays, \DateTimeImmutable $now): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($postedOn, $embargoDays, $now): bool {
            $posted = $this->postedDate($postedOn);
            $cutoff = $now->setTime(0, 0)->modify(sprintf('-%d days', $embargoDays));

            return $posted <= $cutoff;
        });
    }

    private function standardPublishableAfter(string $postedOn, int $embargoDays): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($postedOn, $embargoDays): string {
            $endOfPostedDay = $this->postedDate($postedOn)->setTime(23, 59, 59);
            $eligible = $endOfPostedDay->modify(sprintf('+%d days', $embargoDays));

            return $eligible->format('c');
        });
    }

    private function postedDate(string $postedOn): \DateTimeImmutable
    {
        return DenariusLog::trace(__METHOD__, function () use ($postedOn): \DateTimeImmutable {
            return new \DateTimeImmutable($postedOn . 'T00:00:00');
        });
    }
}
