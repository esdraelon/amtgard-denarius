<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Worker;

use Amtgard\Denarius\Contract\MessageQueue;
use Amtgard\Denarius\Service\CachedKingdomDirectory;
use Amtgard\Denarius\Service\TransactionSynchronizer;

final class LedgerWorker
{
    public const QUEUE = 'denarius-refresh';

    public function __construct(
        private readonly MessageQueue $queue,
        private readonly TransactionSynchronizer $synchronizer,
        private readonly CachedKingdomDirectory $directory,
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

        $processed = 0;
        for ($i = 0; $i < $maxIterations; $i++) {
            $hit = $this->queue->callConsumers(self::QUEUE);
            $processed += $hit;
            if ($hit === 0 && $this->idleMicros > 0) {
                usleep($this->idleMicros);
            }
        }

        return $processed;
    }

    public function handle(string $message): void
    {
        $payload = json_decode($message, true);
        if (!is_array($payload)) {
            return;
        }

        $type = (string) ($payload['type'] ?? '');
        if ($type === 'directory') {
            $this->directory->refresh();
            return;
        }

        if ($type === 'ledger') {
            $this->synchronizer->sync((int) ($payload['orkKingdomId'] ?? 0));
        }
    }
}
