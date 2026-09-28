<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Providers\Readiness\Impl;

use Amtgard\Denarius\Domain\Bank\Providers\Readiness\ProviderReady;
use Optional\Optional;

final class PresentCredentials implements ProviderReady
{
    /**
     * @param list<string> $values
     */
    public function __construct(private readonly array $values)
    {
    }

    public function ready(): bool
    {
        foreach ($this->values as $value) {
            if (!$this->present($value)) {
                return false;
            }
        }

        return $this->values !== [];
    }

    private function present(string $value): bool
    {
        $trimmed = trim($value);

        return Optional::ofNullable($trimmed === '' ? null : $trimmed)->isPresent();
    }
}
