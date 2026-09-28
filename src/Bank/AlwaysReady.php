<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Bank;

final class AlwaysReady implements ProviderReady
{
    public function ready(): bool
    {
        return true;
    }
}
