<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Value object: one manage-queue row for publication review. */
final class TransactionReviewRow
{
    public function __construct(
        private readonly string $tellerTransactionId,
        private readonly string $postedOn,
        private readonly string $amount,
        private readonly string $description,
        private readonly string $accountName,
        private readonly string $status,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    /**
     * @return array<string, string>
     */
    public function view(): array
    {
        return DenariusLog::trace(__METHOD__, function (): array {
            return [
                'tellerTransactionId' => $this->tellerTransactionId,
                'postedOn' => $this->postedOn,
                'amount' => $this->amount,
                'description' => $this->description,
                'accountName' => $this->accountName,
                'status' => $this->status,
            ];
        });
    }
}
