<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy;

use Amtgard\Denarius\Domain\Statement\Line\Money;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Strategy: provider amounts already follow credit-positive signed cents via {@see Money::centsFromDecimal}. */
final class CreditPositiveProviderAmountSign implements ProviderAmountSign
{
    public function signedCents(string $decimalAmount): int
    {
        return DenariusLog::trace(__METHOD__, static fn (): int => Money::centsFromDecimal($decimalAmount));
    }
}
