<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Presentation;

enum DisplayMode: string
{
    case All = 'all';
    case Redacted = 'redacted';
    case Summarized = 'summarized';

    public static function fromStored(string $value): self
    {
        return self::tryFrom($value) ?? self::Summarized;
    }
}
