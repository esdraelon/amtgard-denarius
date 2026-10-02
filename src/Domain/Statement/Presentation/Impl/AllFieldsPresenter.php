<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Presentation\Impl;

use Amtgard\Denarius\Domain\Statement\Presentation\StatementPresenter;
use Amtgard\Denarius\Domain\Statement\Presentation\DisplayMode;
use Amtgard\Denarius\Domain\Statement\Line\LedgerLine;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class AllFieldsPresenter implements StatementPresenter
{
    public function mode(): DisplayMode
    {
        return DenariusLog::trace(__METHOD__, function (): DisplayMode {
            return DisplayMode::All;
        });
    }

    public function present(array $lines): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($lines): array {
            return $lines;
        });
    }
}
