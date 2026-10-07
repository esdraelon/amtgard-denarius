<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy;

use Amtgard\Denarius\Domain\Statement\Line\Money;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Strategy: invert Plaid's positive-outflow amounts to credit-positive signed cents. */
final class PlaidProviderAmountSign implements ProviderAmountSign
{
    public function signedCents(string $decimalAmount): int
    {
        return DenariusLog::trace(__METHOD__, static function () use ($decimalAmount): int {
            return -Money::centsFromDecimal($decimalAmount);
        });
    }
}
