<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Log;

use Throwable;

/** Skips enter/leave tracing; delegates fail and branch lines to the inner log. */
final class QuietMethodLog implements MethodLog
{
    public function __construct(
        private readonly MethodLog $inner,
    ) {
    }

    public function trace(string $method, callable $body): mixed
    {
        try {
            return $body();
        } catch (Throwable $thrown) {
            return $this->inner->trace($method, static function () use ($thrown): mixed {
                throw $thrown;
            });
        }
    }

    public function enter(string $method): string
    {
        return $method;
    }

    public function branch(BranchLogLevel $level, string $branch, string $method, array $context = []): void
    {
        $this->inner->branch($level, $branch, $method, $context);
    }
}
