<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Presentation;

use Amtgard\Denarius\Domain\DisplayMode;
use Amtgard\Denarius\Domain\LedgerLine;

interface StatementPresenter
{
    public function mode(): DisplayMode;

    /**
     * @param list<LedgerLine> $lines
     * @return list<LedgerLine|\Amtgard\Denarius\Domain\CategoryTotal>
     */
    public function present(array $lines): array;
}
