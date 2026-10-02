<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Provider\Framework\Registry;

use Amtgard\Denarius\Domain\Bank\Provider\Framework\LedgerProvider;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\Readiness\ProviderReady;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class ProviderAdmission
{
    public function __construct(
        private readonly LedgerProvider $provider,
        private readonly ProviderReady $ready,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    /**
     * @param list<LedgerProvider> $providers
     * @return list<LedgerProvider>
     */
    public function admit(array $providers): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($providers): array {
            if (!$this->ready->ready()) {
                return $providers;
            }

            $providers[] = $this->provider;

            return $providers;
        });
    }
}
