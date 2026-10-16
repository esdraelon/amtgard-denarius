<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Contract;

/**
 * Fetches one catalog entry from a host-env-vault runtime profile (Port).
 */
interface HostEnvRuntimeSecret
{
    public function fetchEnv(string $profile, string $entry): string;
}
