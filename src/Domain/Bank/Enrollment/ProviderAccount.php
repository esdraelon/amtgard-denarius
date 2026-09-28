<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Enrollment;

final readonly class ProviderAccount
{
    public function __construct(
        public string $id,
        public string $name,
        public string $type,
        public ?string $lastFour,
    ) {
    }
}
