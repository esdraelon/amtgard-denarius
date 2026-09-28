<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Auth;

final class ParsedClaim
{
    public function __construct(
        public readonly string $resource,
        public readonly int $kingdomId,
    ) {
    }
}

final class ClaimOrn
{
    public const PREFIX = 'Denarius';
    public const ADMIN = 'Denarius/Admin';
    public const MANAGE = 'Denarius/ManageKingdom';

    public static function admin(): string
    {
        return self::PREFIX . ':0:0:' . self::ADMIN;
    }

    public static function manage(int $kingdomId): string
    {
        return self::PREFIX . ':0:' . $kingdomId . ':' . self::MANAGE;
    }

    public static function parse(string $orn): ?ParsedClaim
    {
        $parts = explode(':', $orn);
        if (count($parts) !== 4 || $parts[0] !== self::PREFIX) {
            return null;
        }

        if (!ctype_digit($parts[2])) {
            return null;
        }

        $resource = $parts[3];
        if ($resource !== self::ADMIN && $resource !== self::MANAGE) {
            return null;
        }

        return new ParsedClaim($resource, (int) $parts[2]);
    }
}
