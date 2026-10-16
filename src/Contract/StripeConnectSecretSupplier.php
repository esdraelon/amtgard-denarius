<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Contract;

/**
 * Supplies the Stripe platform secret used for Financial Connections enrollment (connect tier).
 */
interface StripeConnectSecretSupplier
{
    public function stripeSecretKey(): string;
}
