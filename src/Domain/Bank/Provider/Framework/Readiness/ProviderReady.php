<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Provider\Framework\Readiness;

interface ProviderReady
{
    public function ready(): bool;
}
