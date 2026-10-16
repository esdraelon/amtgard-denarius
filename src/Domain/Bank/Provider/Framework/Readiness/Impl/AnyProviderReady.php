<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Provider\Framework\Readiness\Impl;

use Amtgard\Denarius\Domain\Bank\Provider\Framework\Readiness\ProviderReady;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/**
 * Admits a provider when any nested readiness check passes (Composite).
 */
final class AnyProviderReady implements ProviderReady
{
    /**
     * @param list<ProviderReady> $checks
     */
    public function __construct(
        private readonly array $checks,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function ready(): bool
    {
        return DenariusLog::trace(__METHOD__, function (): bool {
            foreach ($this->checks as $check) {
                if ($check->ready()) {
                    return true;
                }
            }

            return false;
        });
    }
}
