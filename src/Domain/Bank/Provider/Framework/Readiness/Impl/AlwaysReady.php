<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Provider\Framework\Readiness\Impl;

use Amtgard\Denarius\Domain\Bank\Provider\Framework\Readiness\ProviderReady;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class AlwaysReady implements ProviderReady
{
    public function ready(): bool
    {
        return DenariusLog::trace(__METHOD__, function (): bool {
            return true;
        });
    }
}
