<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Support;

use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;

/** Subscriber: reset open traces before each test. */
final class MethodLogPreparedSubscriber implements PreparedSubscriber
{
    public function notify(Prepared $event): void
    {
        MethodLogRecorder::active()?->reset();
    }
}
