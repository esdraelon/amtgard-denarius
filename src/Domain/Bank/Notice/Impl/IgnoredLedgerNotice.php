<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Notice\Impl;

use Amtgard\Denarius\Domain\Bank\Notice\LedgerNotice;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class IgnoredLedgerNotice implements LedgerNotice
{
    public function action(): string
    {
        return DenariusLog::trace(__METHOD__, function (): string {
            return '';
        });
    }

    public function apply(KingdomRecord $kingdom): void
    {
        DenariusLog::trace(__METHOD__, function (): mixed {
            return null;
        });
    }
}
