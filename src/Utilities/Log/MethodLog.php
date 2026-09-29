<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Log;

interface MethodLog
{
    public function trace(string $method, callable $body): mixed;

    public function enter(string $method): string;
}
