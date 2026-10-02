<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Support;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Framework\Assert;

/** Subscriber: fail when a traced method was not left or failed. */
final class MethodLogFinishedSubscriber implements FinishedSubscriber
{
    public function notify(Finished $event): void
    {
        $logger = MethodLogRecorder::active();
        if ($logger === null) {
            return;
        }

        $open = $logger->openMethods();
        $logger->reset();
        if ($open !== []) {
            Assert::fail('Unbalanced method log: still open ' . implode(', ', $open));
        }
    }
}
