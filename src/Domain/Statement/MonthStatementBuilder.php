<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement;

use Amtgard\Denarius\Domain\Statement\Line\LedgerLine;
use Amtgard\Denarius\Domain\Statement\Presentation\DisplayMode;
use Amtgard\Denarius\Domain\Statement\Presentation\StatementPresenterRegistry;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class MonthStatementBuilder
{
    public function __construct(private readonly StatementPresenterRegistry $presenters)
    {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public static function standard(): self
    {
        return DenariusLog::trace(__METHOD__, static function (): self {
            return new self(StatementPresenterRegistry::standard());
        });
    }

    /**
     * @param list<LedgerLine> $lines
     */
    public function build(array $lines, DisplayMode $mode, MonthWindow $month): MonthStatement
    {
        return DenariusLog::trace(__METHOD__, function () use ($lines, $mode, $month): MonthStatement {
            return new MonthStatement($mode, $month, $this->presenters->for($mode)->present($this->inMonth($lines, $month)));
        });
    }

    /**
     * @param list<LedgerLine> $lines
     * @return list<LedgerLine>
     */
    private function inMonth(array $lines, MonthWindow $month): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($lines, $month): array {
            $inMonth = [];
            foreach ($lines as $line) {
                if ($month->contains($line->getPostedOn())) {
                    $inMonth[] = $line;
                }
            }

            return $inMonth;
        });
    }
}
