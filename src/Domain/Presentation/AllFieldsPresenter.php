<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Presentation;

use Amtgard\Denarius\Domain\DisplayMode;
use Amtgard\Denarius\Domain\LedgerLine;

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
