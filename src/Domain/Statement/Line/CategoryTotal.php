<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Line;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class CategoryTotal
{
    public function __construct(
        public readonly string $category,
        public readonly int $count,
        public readonly int $amountCents,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }
}
