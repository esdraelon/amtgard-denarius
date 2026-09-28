<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Setup;

final class SimpleFinGuide implements SetupGuide
{
    public function id(): string
    {
        return 'simplefin';
    }

    public function instructions(): string
    {
        return <<<'TEXT'
SimpleFIN
1. SimpleFIN has no platform secret, so nothing is written for it.
2. Each kingdom treasurer opens https://bridge.simplefin.org/simplefin/create and pastes that setup token on the kingdom manage page.

TEXT;
    }

    public function fields(): array
    {
        return [];
    }

    public function verify(array $values): bool
    {
        return true;
    }

    public function failure(): string
    {
        return 'SimpleFIN needs no platform credentials.';
    }
}
