<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Access;

enum Visibility: string
{
    case Public = 'public';
    case Registered = 'registered';
    case KingdomOnly = 'kingdom_only';

    public static function fromStored(string $value): self
    {
        return self::tryFrom($value) ?? self::KingdomOnly;
    }
}
