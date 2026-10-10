<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication;

/** Value object: ledger balance bookends for one calendar month (published rows only). */
final class PublicationLedgerBalanceSnapshot
{
    public function __construct(
        public readonly int $openingCents,
        public readonly int $providerEndCents,
    ) {
    }
}
