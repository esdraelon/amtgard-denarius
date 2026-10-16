<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Setup\HostEnv;

use Amtgard\Denarius\Contract\HostEnvRuntimeSecret;
use Amtgard\Denarius\Contract\StripeConnectSecretSupplier;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class HostEnvStripeConnectSecretSupplier implements StripeConnectSecretSupplier
{
    public function __construct(
        private readonly HostEnvRuntimeSecret $runtimeSecrets,
        private readonly string $connectProfile,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function stripeSecretKey(): string
    {
        return DenariusLog::trace(__METHOD__, fn (): string => $this->runtimeSecrets->fetchEnv(
            $this->connectProfile,
            'STRIPE_SECRET_KEY',
        ));
    }
}
