<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Presentation\Impl;

use Amtgard\Denarius\Domain\Statement\Presentation\StatementPresenter;
use Amtgard\Denarius\Domain\Statement\DisplayMode;
use Amtgard\Denarius\Domain\Statement\LedgerLine;

final class AllFieldsPresenter implements StatementPresenter
{
    public function mode(): DisplayMode
    {
        return DisplayMode::All;
    }

    public function present(array $lines): array
    {
        return $lines;
    }
}
