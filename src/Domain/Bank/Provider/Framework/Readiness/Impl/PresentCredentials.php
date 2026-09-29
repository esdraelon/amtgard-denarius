<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Provider\Framework\Readiness\Impl;

use Amtgard\Denarius\Domain\Bank\Provider\Framework\Readiness\ProviderReady;
use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Optional\Optional;

final class PresentCredentials implements ProviderReady
{
    /**
     * @param list<string> $values
     */
    public function __construct(private readonly array $values)
    {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function ready(): bool
    {
        return DenariusLog::trace(__METHOD__, function (): bool {
            foreach ($this->values as $value) {
                if (!$this->present($value)) {
                    return false;
                }
            }

            return $this->values !== [];
        });
    }

    private function present(string $value): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($value): bool {
            $trimmed = trim($value);

            return Optional::ofNullable($trimmed === '' ? null : $trimmed)->isPresent();
        });
    }
}
