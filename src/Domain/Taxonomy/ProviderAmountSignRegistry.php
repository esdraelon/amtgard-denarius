<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Registry: ledger provider id to {@see ProviderAmountSign} strategy. */
final class ProviderAmountSignRegistry
{
    /** @param array<string, ProviderAmountSign> $strategies */
    public function __construct(
        private readonly array $strategies,
        private readonly ProviderAmountSign $default,
    ) {
        DenariusLog::enter(__METHOD__);
    }

    public function forProvider(string $providerId): ProviderAmountSign
    {
        return DenariusLog::trace(__METHOD__, fn (): ProviderAmountSign => $this->strategies[$providerId] ?? $this->default);
    }
}
