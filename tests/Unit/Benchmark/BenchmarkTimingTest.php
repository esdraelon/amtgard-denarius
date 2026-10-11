<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit\Benchmark;

use Amtgard\Denarius\Benchmark\BenchmarkTiming;
use PHPUnit\Framework\TestCase;

final class BenchmarkTimingTest extends TestCase
{
    public function testCollectComputesMedianAndP95(): void
    {
        $timing = BenchmarkTiming::collect(5, static function (): void {
        });

        $this->assertCount(5, $timing->samples());
        $this->assertGreaterThanOrEqual(0.0, $timing->medianMs());
        $this->assertGreaterThanOrEqual($timing->medianMs(), $timing->p95Ms());
    }
}
