<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Access;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

enum Visibility: string
{
    case Public = 'public';
    case Registered = 'registered';
    case KingdomOnly = 'kingdom_only';

    public static function fromStored(string $value): self
    {
        return DenariusLog::trace(__METHOD__, static function () use ($value): self {
            return self::tryFrom($value) ?? self::KingdomOnly;
        });
    }
}
