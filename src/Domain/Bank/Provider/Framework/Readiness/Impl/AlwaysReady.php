<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Provider\Framework\Readiness\Impl;

use Amtgard\Denarius\Domain\Bank\Provider\Framework\Readiness\ProviderReady;

final class AlwaysReady implements ProviderReady
{
    public function ready(): bool
    {
        return true;
    }
}
