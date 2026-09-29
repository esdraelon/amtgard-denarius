<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Provider\Framework\Registry;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class ConfiguredLedgerProviders
{
    /**
     * @param list<ProviderAdmission> $admissions
     */
    public function __construct(private readonly array $admissions)
    {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function registry(): LedgerProviderRegistry
    {
        return DenariusLog::trace(__METHOD__, function (): LedgerProviderRegistry {
            $providers = [];
            foreach ($this->admissions as $admission) {
                $providers = $admission->admit($providers);
            }

            return new LedgerProviderRegistry($providers);
        });
    }
}
