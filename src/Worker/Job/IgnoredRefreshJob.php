<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Worker\Job;

final class IgnoredRefreshJob implements RefreshJob
{
    public function type(): string
    {
        return '';
    }

    public function handle(array $payload): void
    {
    }
}
