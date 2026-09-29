<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Setup\Guide\Impl;

use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Amtgard\Denarius\Utilities\Setup\Guide\SetupGuide;

final class SimpleFinGuide implements SetupGuide
{
    public function id(): string
    {
        return DenariusLog::trace(__METHOD__, function (): string {
            return 'simplefin';
        });
    }

    public function instructions(): string
    {
        return DenariusLog::trace(__METHOD__, function (): string {
            return <<<'TEXT'
SimpleFIN
1. SimpleFIN has no platform secret, so nothing is written for it.
2. Each kingdom treasurer opens https://bridge.simplefin.org/simplefin/create and pastes that setup token on the kingdom manage page.

TEXT;
        });
    }

    public function fields(): array
    {
        return DenariusLog::trace(__METHOD__, function (): array {
            return [];
        });
    }

    public function verify(array $values): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($values): bool {
            return true;
        });
    }

    public function failure(): string
    {
        return DenariusLog::trace(__METHOD__, function (): string {
            return 'SimpleFIN needs no platform credentials.';
        });
    }
}
