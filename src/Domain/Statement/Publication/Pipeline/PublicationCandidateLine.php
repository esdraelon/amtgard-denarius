<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication\Pipeline;

use Amtgard\Denarius\Domain\Statement\Line\LedgerLine;
use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Data;

/** Value object: one transaction row entering the publication pipeline. */
final class PublicationCandidateLine
{
    use Builder;
    use Data;

    private function __construct(
        private string $tellerTransactionId = '',
        private string $postedOn = '',
        private int $amountCents = 0,
        private int $categoryId = 0,
        private string $category = '',
        private string $categoryFlow = '',
        private string $categorySource = '',
        private int $categoryConfidence = 0,
        private string $description = '',
        private string $counterparty = '',
        private string $status = '',
        private string $accountName = '',
        private ?string $publishedAt = null,
        private ?string $publishableAfter = null,
        private ?string $publicationFlags = null,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function toLedgerLine(): LedgerLine
    {
        return DenariusLog::trace(__METHOD__, function (): LedgerLine {
            return LedgerLine::builder()
                ->postedOn($this->postedOn)
                ->amountCents($this->amountCents)
                ->category($this->category)
                ->categoryFlow($this->categoryFlow)
                ->description($this->description)
                ->counterparty($this->counterparty)
                ->status($this->status)
                ->accountName($this->accountName)
                ->build();
        });
    }
}
