<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Auth;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class CurrentActor
{
    private static ?string $idpUserId = null;

    public static function set(?string $idpUserId): void
    {
        DenariusLog::trace(__METHOD__, static function () use ($idpUserId): mixed {
            self::$idpUserId = $idpUserId;

            return null;
        });
    }

    public static function id(): ?string
    {
        return DenariusLog::trace(__METHOD__, static function (): ?string {
            return self::$idpUserId;
        });
    }

    public static function editedById(): ?int
    {
        return DenariusLog::trace(__METHOD__, static function (): ?int {
            if (self::$idpUserId === null || !ctype_digit(self::$idpUserId)) {
                return null;
            }

            return (int) self::$idpUserId;
        });
    }

    public static function reset(): void
    {
        DenariusLog::trace(__METHOD__, static function (): mixed {
            self::$idpUserId = null;

            return null;
        });
    }
}
