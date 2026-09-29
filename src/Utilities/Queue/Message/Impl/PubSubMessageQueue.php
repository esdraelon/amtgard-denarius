<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Queue\Message\Impl;

use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Amtgard\Denarius\Utilities\Queue\Message\MessageQueue;
use Amtgard\SetQueue\PubSubQueue;

final class PubSubMessageQueue implements MessageQueue
{
    public function __construct(
        private readonly PubSubQueue $queue,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function publish(string $queue, string $key, string $message): void
    {
        DenariusLog::trace(__METHOD__, function () use ($queue, $key, $message): mixed {
            $this->queue->publish($queue, $key, $message, true);

            return null;
        });
    }

    public function redrive(string $queue): void
    {
        DenariusLog::trace(__METHOD__, function () use ($queue): mixed {
            $this->queue->redrive($queue);

            return null;
        });
    }

    public function subscribe(string $queue, callable $callback, ?callable $failure): void
    {
        DenariusLog::trace(__METHOD__, function () use ($queue, $callback, $failure): mixed {
            $this->queue->subscribe($queue, $callback, $failure);

            return null;
        });
    }

    public function callConsumers(string $queue): int
    {
        return DenariusLog::trace(__METHOD__, function () use ($queue): int {
            $this->queue->callConsumers($queue, 1);

            return 0;
        });
    }
}
