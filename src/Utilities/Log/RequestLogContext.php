<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Log;

/** Ambient Context: request id for the current process. */
final class RequestLogContext
{
    private static ?string $requestId = null;

    public static function set(?string $requestId): void
    {
        DenariusLog::trace(__METHOD__, static function () use ($requestId): mixed {
            self::$requestId = $requestId;

            return null;
        });
    }

    public static function id(): ?string
    {
        return self::$requestId;
    }

    public static function reset(): void
    {
        DenariusLog::trace(__METHOD__, static function (): mixed {
            self::$requestId = null;

            return null;
        });
    }
}
