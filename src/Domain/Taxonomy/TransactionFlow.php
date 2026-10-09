<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Value object: income, expense, or transfer section for a category slug. */
enum TransactionFlow: string
{
    case Income = 'income';
    case Expense = 'expense';
    case Transfer = 'transfer';

    public static function fromStored(string $value): ?self
    {
        return DenariusLog::trace(__METHOD__, static fn (): ?self => self::tryFrom($value));
    }

    /**
     * Default flow from credit-positive signed cents (negative = outflow).
     *
     * Provider-specific sign is applied by {@see ProviderAmountSign} before this runs at ingest (M-TAX-03).
     */
    public static function defaultFromSignedCents(int $signedCents): self
    {
        return DenariusLog::trace(__METHOD__, static function () use ($signedCents): self {
            if ($signedCents > 0) {
                return self::Income;
            }

            return self::Expense;
        });
    }
}
