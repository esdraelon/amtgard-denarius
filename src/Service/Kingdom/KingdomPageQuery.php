<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Kingdom;

use Amtgard\Denarius\Domain\Statement\MonthStatement;
use Amtgard\Denarius\Domain\Statement\MonthStatementBuilder;
use Amtgard\Denarius\Domain\Statement\MonthWindow;
use Amtgard\Denarius\Domain\Statement\Presentation\DisplayMode;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\PublicationCandidateLine;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\PublicationEnvelope;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\PublicationPipeline;
use Amtgard\Denarius\Domain\Statement\Publication\PublicationLedgerBalanceResolver;
use Amtgard\Denarius\Domain\Statement\Publication\StatementAbsenceClassifier;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Service\Month\MonthReader;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Public read path: month statements from the public publication pipeline only. */
final class KingdomPageQuery implements MonthReader
{
    public function __construct(
        private readonly KingdomPublicationLineSource $lines,
        private readonly PublicationPipeline $publicPipeline,
        private readonly MonthStatementBuilder $builder,
        private readonly StatementAbsenceClassifier $absence,
        private readonly \DateTimeImmutable $asOf,
        private readonly PublicationLedgerBalanceResolver $balances = new PublicationLedgerBalanceResolver(),
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function statement(KingdomRecord $kingdom, MonthWindow $month): MonthStatement
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdom, $month): MonthStatement {
            return $this->statementForMode($kingdom, $month, DisplayMode::fromStored($kingdom->getDisplayMode()));
        });
    }

    public function statementForMode(KingdomRecord $kingdom, MonthWindow $month, DisplayMode $mode): MonthStatement
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdom, $month, $mode): MonthStatement {
            $candidates = $this->lines->candidates($kingdom);
            $books = $this->balances->snapshotForMonth($month, $candidates);
            $envelope = new PublicationEnvelope(
                $kingdom,
                $month,
                $mode,
                $this->asOf,
                $this->candidatesInMonth($candidates, $month),
                $books->providerEndCents,
                $books->openingCents,
            );
            $envelope = $this->publicPipeline->run($envelope);
            $statement = $this->builder->build($envelope->toLedgerLines(), $mode, $month, $kingdom);
            if ($statement->rows !== []) {
                return $this->attachPublishedBalances($statement, $envelope);
            }

            return $this->attachPublishedBalances(
                new MonthStatement(
                    $statement->mode,
                    $statement->month,
                    $statement->rows,
                    $this->absence->classify($month, $this->asOf, $candidates),
                ),
                $envelope,
            );
        });
    }

    private function attachPublishedBalances(MonthStatement $statement, PublicationEnvelope $envelope): MonthStatement
    {
        return new MonthStatement(
            $statement->mode,
            $statement->month,
            $statement->rows,
            $statement->absenceReason,
            $envelope->lastPublishedBalanceCents(),
            $envelope->publishedBalanceCents(),
        );
    }

    /**
     * @param list<PublicationCandidateLine> $candidates
     * @return list<PublicationCandidateLine>
     */
    private function candidatesInMonth(array $candidates, MonthWindow $month): array
    {
        $inMonth = [];
        foreach ($candidates as $line) {
            if ($month->contains($line->getPostedOn())) {
                $inMonth[] = $line;
            }
        }

        return $inMonth;
    }
}
