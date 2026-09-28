<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Bank;

interface ProviderReady
{
    public function ready(): bool;
}
