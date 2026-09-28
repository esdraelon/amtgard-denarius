<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Auth;

final class CurrentActor
{
    private static ?string $idpUserId = null;

    public static function set(?string $idpUserId): void
    {
        self::$idpUserId = $idpUserId;
    }

    public static function id(): ?string
    {
        return self::$idpUserId;
    }

    public static function editedById(): ?int
    {
        if (self::$idpUserId === null || !ctype_digit(self::$idpUserId)) {
            return null;
        }

        return (int) self::$idpUserId;
    }

    public static function reset(): void
    {
        self::$idpUserId = null;
    }
}
