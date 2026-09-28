<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement;

use Amtgard\Denarius\Domain\Statement\Presentation\StatementPresenterRegistry;

final class MonthStatementBuilder
{
    public function __construct(private readonly StatementPresenterRegistry $presenters)
    {
    }

    public static function standard(): self
    {
        return new self(StatementPresenterRegistry::standard());
    }

    /**
     * @param list<LedgerLine> $lines
     */
    public function build(array $lines, DisplayMode $mode, MonthWindow $month): MonthStatement
    {
        return new MonthStatement($mode, $month, $this->presenters->for($mode)->present($this->inMonth($lines, $month)));
    }

    /**
     * @param list<LedgerLine> $lines
     * @return list<LedgerLine>
     */
    private function inMonth(array $lines, MonthWindow $month): array
    {
        $inMonth = [];
        foreach ($lines as $line) {
            if ($month->contains($line->getPostedOn())) {
                $inMonth[] = $line;
            }
        }

        return $inMonth;
    }
}
