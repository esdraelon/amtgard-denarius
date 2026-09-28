<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Providers\Readiness;

interface ProviderReady
{
    public function ready(): bool;
}
