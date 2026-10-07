<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Line;

use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Data;

final class LedgerLine
{
    use Builder;
    use Data;

    private function __construct(
        private string $postedOn = '',
        private int $amountCents = 0,
        private string $category = '',
        private string $categoryFlow = '',
        private string $description = '',
        private string $counterparty = '',
        private string $status = '',
        private string $accountName = '',
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }
}
