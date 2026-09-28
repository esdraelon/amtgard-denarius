<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain;

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
    ];

    public static function fromName(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';

        return trim($slug, '-');
    }

    public static function isReserved(string $slug): bool
    {
        return in_array($slug, self::RESERVED, true);
    }
}
