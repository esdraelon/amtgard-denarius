<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Presentation\Impl;

use Amtgard\Denarius\Domain\Statement\Line\LedgerLine;
use Amtgard\Denarius\Domain\Statement\Presentation\DisplayMode;
use Amtgard\Denarius\Domain\Statement\Presentation\StatementPresenter;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Presents pipeline output for the less-redacted tier without adding raw provider fields. */
final class LessRedactedPresenter implements StatementPresenter
{
    public function mode(): DisplayMode
    {
        return DenariusLog::trace(__METHOD__, function (): DisplayMode {
            return DisplayMode::LessRedacted;
        });
    }

    /**
     * @param list<LedgerLine> $lines
     *
     * @return list<LedgerLine>
     */
    public function present(array $lines): array
    {
        return DenariusLog::trace(__METHOD__, fn (): array => $lines);
    }
}
