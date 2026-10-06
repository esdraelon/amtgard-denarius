<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Kingdom;

use Amtgard\Denarius\Domain\Statement\MonthStatement;
use Amtgard\Denarius\Domain\Statement\MonthStatementBuilder;
use Amtgard\Denarius\Domain\Statement\MonthWindow;
use Amtgard\Denarius\Domain\Statement\Presentation\DisplayMode;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\PublicationEnvelope;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\PublicationPipeline;
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
        private readonly \DateTimeImmutable $asOf,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function statement(KingdomRecord $kingdom, MonthWindow $month): MonthStatement
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdom, $month): MonthStatement {
            $mode = DisplayMode::fromStored($kingdom->getDisplayMode());
            $envelope = new PublicationEnvelope(
                $kingdom,
                $month,
                $mode,
                $this->asOf,
                $this->lines->candidates($kingdom),
            );
            $envelope = $this->publicPipeline->run($envelope);

            return $this->builder->build($envelope->toLedgerLines(), $mode, $month);
        });
    }
}
