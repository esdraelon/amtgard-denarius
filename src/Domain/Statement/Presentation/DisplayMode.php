<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Presentation;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

enum DisplayMode: string
{
    case All = 'all';
    case Redacted = 'redacted';
    case Summarized = 'summarized';

    public static function fromStored(string $value): self
    {
        return DenariusLog::trace(__METHOD__, static function () use ($value): self {
            return self::tryFrom($value) ?? self::Summarized;
        });
    }
}
