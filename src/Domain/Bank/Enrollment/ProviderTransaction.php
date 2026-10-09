<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Enrollment;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

final readonly class ProviderTransaction
{
    public function __construct(
        public string $id,
        public string $postedOn,
        public string $amount,
        /** Provider hint (Plaid/Teller raw category); not a taxonomy slug. */
        public string $category,
        public string $description,
        public string $counterparty,
        public string $status,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }
}
