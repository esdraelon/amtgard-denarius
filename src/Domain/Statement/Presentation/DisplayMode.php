<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Presentation;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

enum DisplayMode: string
{
    /** @deprecated Stored as less_redacted; use {@see LessRedacted}. */
    case All = 'all';
    case LessRedacted = 'less_redacted';
    case Redacted = 'redacted';
    case Summarized = 'summarized';

    public static function fromStored(string $value): self
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, static function () use ($value, $method): self {
            if ($value === self::All->value) {
                DenariusLog::debugBranch('display_mode_legacy_all', $method, ['stored' => $value]);

                return self::LessRedacted;
            }

            return self::tryFrom($value) ?? self::Summarized;
        });
    }

    public function label(): string
    {
        return DenariusLog::trace(__METHOD__, fn (): string => match ($this) {
            self::LessRedacted, self::All => 'Less redacted (pipeline)',
            self::Redacted => 'Redacted lines',
            self::Summarized => 'Summarized only',
        });
    }
}
