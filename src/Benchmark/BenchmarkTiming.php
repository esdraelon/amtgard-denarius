<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Benchmark;

/** Value object: sample timings in milliseconds. */
final class BenchmarkTiming
{
    /**
     * @param list<float> $samplesMs
     */
    private function __construct(
        private readonly array $samplesMs,
    ) {
    }

    /**
     * @param callable(): mixed $operation
     */
    public static function measure(callable $operation): self
    {
        $start = hrtime(true);
        $operation();
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        return new self([$elapsedMs]);
    }

    /**
     * @param callable(): mixed $operation
     */
    public static function collect(int $iterations, callable $operation): self
    {
        $samples = [];
        for ($i = 0; $i < $iterations; ++$i) {
            $start = hrtime(true);
            $operation();
            $samples[] = (hrtime(true) - $start) / 1_000_000;
        }

        return new self($samples);
    }

    public function medianMs(): float
    {
        $sorted = $this->samplesMs;
        sort($sorted);
        $count = count($sorted);
        if ($count === 0) {
            return 0.0;
        }
        $mid = intdiv($count, 2);
        if ($count % 2 === 1) {
            return $sorted[$mid];
        }

        return ($sorted[$mid - 1] + $sorted[$mid]) / 2;
    }

    public function p95Ms(): float
    {
        $sorted = $this->samplesMs;
        sort($sorted);
        $count = count($sorted);
        if ($count === 0) {
            return 0.0;
        }
        $index = (int) ceil(0.95 * $count) - 1;

        return $sorted[max(0, min($index, $count - 1))];
    }

    /**
     * @return list<float>
     */
    public function samples(): array
    {
        return $this->samplesMs;
    }
}
