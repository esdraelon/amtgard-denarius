<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Ledger;

use Amtgard\Denarius\Domain\Bank\Provider\Framework\Registry\LedgerProviderRegistry;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Optional\Optional;

/** Strategy: kingdom-stored provider id with registry default fallback. */
final class LedgerProviderIdResolver
{
    public function __construct(private readonly LedgerProviderRegistry $providers)
    {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function forKingdom(KingdomRecord $kingdom): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdom): string {
            $stored = $kingdom->getProvider();

            return Optional::ofNullable($stored === null || $stored === '' ? null : $stored)
                ->orElse($this->providers->default()->id());
        });
    }
}
