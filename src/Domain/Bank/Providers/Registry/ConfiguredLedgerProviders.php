<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Providers\Registry;

final class ConfiguredLedgerProviders
{
    /**
     * @param list<ProviderAdmission> $admissions
     */
    public function __construct(private readonly array $admissions)
    {
    }

    public function registry(): LedgerProviderRegistry
    {
        $providers = [];
        foreach ($this->admissions as $admission) {
            $providers = $admission->admit($providers);
        }

        return new LedgerProviderRegistry($providers);
    }
}
