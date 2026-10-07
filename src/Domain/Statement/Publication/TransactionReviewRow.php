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
        private readonly int $amountCents,
        private readonly string $description,
        private readonly string $counterparty,
        private readonly string $accountName,
        private readonly string $status,
        private readonly string $category,
        private readonly string $categoryLabel,
        private readonly string $categorySource,
        private readonly ?string $categorySuggested,
        private readonly int $categoryConfidence,
        private readonly string $prefillSlug,
        private readonly string $prefillDisplay,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    /**
     * @return array<string, mixed>
     */
    public function view(): array
    {
        return DenariusLog::trace(__METHOD__, function (): array {
            return [
                'tellerTransactionId' => $this->tellerTransactionId,
                'postedOn' => $this->postedOn,
                'amount' => $this->amount,
                'amountCents' => $this->amountCents,
                'description' => $this->description,
                'counterparty' => $this->counterparty,
                'accountName' => $this->accountName,
                'status' => $this->status,
                'category' => $this->category,
                'categoryLabel' => $this->categoryLabel,
                'categorySource' => $this->categorySource,
                'categorySuggested' => $this->categorySuggested ?? '',
                'categoryConfidence' => $this->categoryConfidence,
                'prefillSlug' => $this->prefillSlug,
                'prefillDisplay' => $this->prefillDisplay,
            ];
        });
    }
}
