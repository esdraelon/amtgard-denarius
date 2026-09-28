<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Worker\Job;

use Amtgard\Denarius\Service\CachedKingdomDirectory;

final class DirectoryRefreshJob implements RefreshJob
{
    public function __construct(private readonly CachedKingdomDirectory $directory)
    {
    }

    public function type(): string
    {
        return 'directory';
    }

    public function handle(array $payload): void
    {
        $this->directory->refresh();
    }
}
