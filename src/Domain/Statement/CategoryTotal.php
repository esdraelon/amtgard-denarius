<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement;

final class CategoryTotal
{
    public function __construct(
        public readonly string $category,
        public readonly int $count,
        public readonly int $amountCents,
    ) {
    }
}
