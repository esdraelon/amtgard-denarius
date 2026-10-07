<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Value object: provenance of a stored category slug on a transaction row. */
enum CategorySource: string
{
    case Manager = 'manager';
    case KingdomRule = 'kingdom_rule';
    case SharedRule = 'shared_rule';
    case ProviderHint = 'provider_hint';
    case Fallback = 'fallback';

    public static function fromStored(string $value): self
    {
        return DenariusLog::trace(__METHOD__, static fn (): self => self::tryFrom($value) ?? self::Fallback);
    }
}
