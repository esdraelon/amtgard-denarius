<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Queue;

use Amtgard\Denarius\Contract\MessageQueue;
use Amtgard\SetQueue\PubSubQueue;

final class PubSubMessageQueue implements MessageQueue
{
    public function __construct(
        private readonly PubSubQueue $queue,
    ) {
    }

    public function publish(string $queue, string $key, string $message): void
    {
        $this->queue->publish($queue, $key, $message, true);
    }

    public function redrive(string $queue): void
    {
        $this->queue->redrive($queue);
    }

    public function subscribe(string $queue, callable $callback, ?callable $failure): void
    {
        $this->queue->subscribe($queue, $callback, $failure);
    }

    public function callConsumers(string $queue): int
    {
        $this->queue->callConsumers($queue, 1);

        return 0;
    }
}
