<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Enrollment;

final readonly class ProviderTransaction
{
    public function __construct(
        public string $id,
        public string $postedOn,
        public string $amount,
        public string $category,
        public string $description,
        public string $counterparty,
        public string $status,
    ) {
    }
}
