<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Bank;

final class ProviderAdmission
{
    public function __construct(
        private readonly LedgerProvider $provider,
        private readonly ProviderReady $ready,
    ) {
    }

    /**
     * @param list<LedgerProvider> $providers
     * @return list<LedgerProvider>
     */
    public function admit(array $providers): array
    {
        if (!$this->ready->ready()) {
            return $providers;
        }

        $providers[] = $this->provider;

        return $providers;
    }
}
