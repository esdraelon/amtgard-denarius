<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Worker\Job;

interface RefreshJob
{
    public function type(): string;

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): void;
}
