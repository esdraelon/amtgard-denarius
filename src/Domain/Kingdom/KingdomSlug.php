<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Kingdom;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class KingdomSlug
{
    /** @var list<string> */
    public const RESERVED = [
        'login',
        'logout',
        'oauth',
        'admin',
        'manage',
        'version',
        'webhooks',
        'health',
        'privacy-policy',
    ];

    public static function fromName(string $name): string
    {
        return DenariusLog::trace(__METHOD__, static function () use ($name): string {
            $slug = strtolower(trim($name));
            $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';

            return trim($slug, '-');
        });
    }

    public static function isReserved(string $slug): bool
    {
        return DenariusLog::trace(__METHOD__, static function () use ($slug): bool {
            return in_array($slug, self::RESERVED, true);
        });
    }
}
