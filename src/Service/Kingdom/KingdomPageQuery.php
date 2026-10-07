<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Kingdom;

use Amtgard\Denarius\Domain\Statement\MonthStatement;
use Amtgard\Denarius\Domain\Statement\MonthStatementBuilder;
use Amtgard\Denarius\Domain\Statement\MonthWindow;
use Amtgard\Denarius\Domain\Statement\Presentation\DisplayMode;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\PublicationEnvelope;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\PublicationPipeline;
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
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function statement(KingdomRecord $kingdom, MonthWindow $month): MonthStatement
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdom, $month): MonthStatement {
            $mode = DisplayMode::fromStored($kingdom->getDisplayMode());
            $candidates = $this->lines->candidates($kingdom);
            $envelope = new PublicationEnvelope(
                $kingdom,
                $month,
                $mode,
                $this->asOf,
                $candidates,
            );
            $envelope = $this->publicPipeline->run($envelope);
            $statement = $this->builder->build($envelope->toLedgerLines(), $mode, $month, $kingdom);
            if ($statement->rows !== []) {
                return $statement;
            }

            return new MonthStatement(
                $statement->mode,
                $statement->month,
                $statement->rows,
                $this->absence->classify($month, $this->asOf, $candidates),
            );
        });
    }
}
