<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Support;

use Amtgard\Denarius\Contract\StripeConnectSecretSupplier;
use LogicException;

final class UnreachableStripeConnectSecretSupplier implements StripeConnectSecretSupplier
{
    public function stripeSecretKey(): string
    {
        throw new LogicException('Stripe connect secret was not expected in this test.');
    }
}
