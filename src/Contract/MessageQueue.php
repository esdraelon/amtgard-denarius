<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Contract;

interface MessageQueue
{
    public function publish(string $queue, string $key, string $message): void;

    public function redrive(string $queue): void;

    public function subscribe(string $queue, callable $callback, ?callable $failure): void;

    public function callConsumers(string $queue): int;
}
