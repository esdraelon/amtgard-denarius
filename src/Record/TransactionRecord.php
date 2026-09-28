<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Record;

use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Data;

final class TransactionRecord
{
    use Builder;
    use Data;

    private function __construct(
        private ?int $id = null,
        private int $kingdomId = 0,
        private string $tellerTransactionId = '',
        private string $tellerAccountId = '',
        private string $postedOn = '',
        private int $amountCents = 0,
        private string $category = 'general',
        private string $description = '',
        private string $counterparty = '',
        private string $status = '',
    ) {
    }
}
