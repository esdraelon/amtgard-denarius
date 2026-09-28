<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Worker;

use Amtgard\Denarius\Utilities\Queue\Message\MessageQueue;
use Amtgard\Denarius\Worker\Job\RefreshJobRegistry;

final class LedgerWorker
{
    public const QUEUE = 'denarius-refresh';

    public function __construct(
        private readonly MessageQueue $queue,
        private readonly RefreshJobRegistry $jobs,
        private readonly int $idleMicros = 100000,
    ) {
    }

    public function run(int $maxIterations = PHP_INT_MAX): int
    {
        $this->queue->redrive(self::QUEUE);
        $this->queue->subscribe(self::QUEUE, function (string $key, string $message): void {
            $this->handle($message);
        }, function (\Exception $exception, string $key, string $message): void {
            $this->queue->publish(self::QUEUE, $key, $message);
        });

        return $this->poll($maxIterations);
    }

    public function handle(string $message): void
    {
        $payload = $this->payload($message);
        if ($payload === null) {
            return;
        }

        $this->jobs->find($this->type($payload))->handle($payload);
    }

    private function poll(int $maxIterations): int
    {
        $processed = 0;
        for ($i = 0; $i < $maxIterations; $i++) {
            $hit = $this->queue->callConsumers(self::QUEUE);
            $processed += $hit;
            $this->idle($hit);
        }

        return $processed;
    }

    private function idle(int $hit): void
    {
        if ($hit === 0 && $this->idleMicros > 0) {
            usleep($this->idleMicros);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function payload(string $message): ?array
    {
        $payload = json_decode($message, true);

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function type(array $payload): string
    {
        return (string) ($payload['type'] ?? '');
    }
}
