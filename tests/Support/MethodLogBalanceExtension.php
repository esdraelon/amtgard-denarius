<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Support;

use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/** Extension: fail a test when a traced method was not left or failed. */
final class MethodLogBalanceExtension implements Extension
{
    public function bootstrap(
        Configuration $configuration,
        Facade $facade,
        ParameterCollection $parameters,
    ): void {
        $facade->registerSubscribers(
            new MethodLogPreparedSubscriber(),
            new MethodLogFinishedSubscriber(),
        );
    }
}
