<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Providers\Registry;

use Amtgard\Denarius\Domain\Bank\Providers\LedgerProvider;
use Amtgard\Denarius\Domain\Bank\Providers\Impl\MissingLedgerProvider;

final class LedgerProviderRegistry
{
    /** @var array<string, LedgerProvider> */
    private array $byId;

    /**
     * @param list<LedgerProvider> $providers
     */
    public function __construct(
        private readonly array $providers,
        private readonly LedgerProvider $missing = new MissingLedgerProvider(),
    ) {
        $indexed = [];
        foreach ($providers as $provider) {
            $indexed[$provider->id()] = $provider;
        }
        $this->byId = $indexed;
    }

    public function find(string $id): LedgerProvider
    {
        return $this->byId[$id] ?? $this->missing;
    }

    public function default(): LedgerProvider
    {
        return $this->providers[0] ?? $this->missing;
    }

    /**
     * @param list<string> $skipped
     */
    public function resolve(string $institution, array $skipped): LedgerProvider
    {
        foreach ($this->providers as $provider) {
            if ($this->skipped($provider, $skipped) || $provider->supports($institution)->rejected()) {
                continue;
            }

            return $provider;
        }

        return $this->missing;
    }

    /**
     * @param list<string> $skipped
     */
    private function skipped(LedgerProvider $provider, array $skipped): bool
    {
        return in_array($provider->id(), $skipped, true);
    }
}
