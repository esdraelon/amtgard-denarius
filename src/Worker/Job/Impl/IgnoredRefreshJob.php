<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Worker\Job\Impl;

use Amtgard\Denarius\Worker\Job\RefreshJob;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class IgnoredRefreshJob implements RefreshJob
{
    public function type(): string
    {
        return DenariusLog::trace(__METHOD__, function (): string {
            return '';
        });
    }

    public function handle(array $payload): void
    {
        DenariusLog::trace(__METHOD__, function (): mixed {
            return null;
        });
    }
}
