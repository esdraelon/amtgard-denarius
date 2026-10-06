<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication;

use Amtgard\Denarius\Domain\Statement\MonthWindow;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\PublicationCandidateLine;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Specification: picks the public absence banner from pipeline candidates. */
final class StatementAbsenceClassifier
{
    /**
     * @param list<PublicationCandidateLine> $candidates
     */
    public function classify(MonthWindow $month, \DateTimeImmutable $asOf, array $candidates): StatementAbsenceReason
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($method, $month, $asOf, $candidates): StatementAbsenceReason {
            $inMonth = $this->inMonth($candidates, $month);
            if ($inMonth !== []) {
                if ($this->hasUnreviewed($inMonth, $asOf)) {
                    DenariusLog::debugBranch('statement_absence_unreviewed', $method, ['month' => $month->key()]);

                    return StatementAbsenceReason::unreviewed();
                }

                DenariusLog::debugBranch('statement_absence_stale', $method, ['month' => $month->key()]);

                return StatementAbsenceReason::staleTransactions();
            }

            $since = $this->lastPostedOnOrBefore($candidates, $month) ?? $month->startDate();
            DenariusLog::debugBranch('statement_absence_no_since', $method, [
                'month' => $month->key(),
                'since' => $since,
            ]);

            return StatementAbsenceReason::noCurrentSince($since);
        });
    }

    /**
     * @param list<PublicationCandidateLine> $candidates
     * @return list<PublicationCandidateLine>
     */
    private function inMonth(array $candidates, MonthWindow $month): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($candidates, $month): array {
            $inMonth = [];
            foreach ($candidates as $line) {
                if ($month->contains($line->getPostedOn())) {
                    $inMonth[] = $line;
                }
            }

            return $inMonth;
        });
    }

    /**
     * @param list<PublicationCandidateLine> $lines
     */
    private function hasUnreviewed(array $lines, \DateTimeImmutable $asOf): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($lines, $asOf): bool {
            foreach ($lines as $line) {
                if ($this->isPublished($line)) {
                    continue;
                }
                if ($this->embargoOpen($line, $asOf)) {
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * @param list<PublicationCandidateLine> $candidates
     */
    private function lastPostedOnOrBefore(array $candidates, MonthWindow $month): ?string
    {
        return DenariusLog::trace(__METHOD__, function () use ($candidates, $month): ?string {
            $end = (new \DateTimeImmutable($month->startDate()))->modify('last day of this month')->format('Y-m-d');
            $latest = null;
            foreach ($candidates as $line) {
                $posted = $line->getPostedOn();
                if ($posted > $end) {
                    continue;
                }
                if ($latest === null || $posted > $latest) {
                    $latest = $posted;
                }
            }

            return $latest;
        });
    }

    private function isPublished(PublicationCandidateLine $line): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($line): bool {
            $publishedAt = $line->getPublishedAt();

            return $publishedAt !== null && $publishedAt !== '';
        });
    }

    private function embargoOpen(PublicationCandidateLine $line, \DateTimeImmutable $asOf): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($line, $asOf): bool {
            $after = $line->getPublishableAfter();
            if ($after === null || $after === '') {
                return true;
            }

            return $asOf >= new \DateTimeImmutable($after);
        });
    }
}
