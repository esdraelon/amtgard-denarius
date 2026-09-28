<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain;

final class MonthStatement
{
    /**
     * @param list<LedgerLine|CategoryTotal> $rows
     */
    public function __construct(
        public readonly DisplayMode $mode,
        public readonly MonthWindow $month,
        public readonly array $rows,
    ) {
    }
}
