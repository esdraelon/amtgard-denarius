<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy;

/**
 * Strategy: map a provider decimal amount to credit-positive signed cents.
 *
 * Denarius uses one convention for categorization: negative cents = money out, positive = money in.
 * We normalize at the categorizer via per-provider strategies (not inside provider adapters in M-TAX-01)
 * so sync output stays unchanged until M-TAX-03 wires {@see TransactionFlow::defaultFromSignedCents}.
 *
 * @see PlaidProviderAmountSign Plaid reports outflows as positive decimals.
 * @see CreditPositiveProviderAmountSign Teller, Stripe FC, and SimpleFin already use negative outflows.
 */
interface ProviderAmountSign
{
    public function signedCents(string $decimalAmount): int;
}
