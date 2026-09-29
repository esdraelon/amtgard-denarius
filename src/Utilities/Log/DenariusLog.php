<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Log;

use LogicException;

/** Facade: process-wide method log installed once per entrypoint. */
final class DenariusLog
{
    private static ?MethodLog $logger = null;

    public static function install(MethodLog $logger): void
    {
        self::$logger = $logger;
    }

    public static function trace(string $method, callable $body): mixed
    {
        return self::installed()->trace($method, $body);
    }

    public static function enter(string $method): string
    {
        return self::installed()->enter($method);
    }

    private static function installed(): MethodLog
    {
        if (self::$logger === null) {
            throw new LogicException('DenariusLog is not installed.');
        }

        return self::$logger;
    }
}
