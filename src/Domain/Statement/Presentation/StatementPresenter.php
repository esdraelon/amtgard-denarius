<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Presentation;

use Amtgard\Denarius\Domain\Statement\Presentation\DisplayMode;
use Amtgard\Denarius\Domain\Statement\Line\LedgerLine;

interface StatementPresenter
{
    public function mode(): DisplayMode;

    /**
     * @param list<LedgerLine> $lines
     * @return list<LedgerLine|\Amtgard\Denarius\Domain\Statement\Line\CategoryTotal>
     */
    public function present(array $lines, int $summarizedCategoryMinLines = 2): array;
}
