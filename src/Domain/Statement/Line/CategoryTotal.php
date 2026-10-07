<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Line;

use Amtgard\Denarius\Domain\Taxonomy\TransactionFlow;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class CategoryTotal
{
    public function __construct(
        public readonly string $category,
        public readonly int $count,
        public readonly int $amountCents,
        public readonly ?TransactionFlow $flowSection = null,
        public readonly bool $isNetTotal = false,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public static function net(int $amountCents): self
    {
        return DenariusLog::trace(__METHOD__, fn (): self => new self(
            'Net change (excludes transfers)',
            0,
            $amountCents,
            null,
            true,
        ));
    }
}
