<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Setup\Client;

interface SetupClient
{
    /**
     * @param list<string> $headers
     */
    public function status(string $method, string $url, array $headers, string $body): int;

    public function readable(string $path): bool;
}
