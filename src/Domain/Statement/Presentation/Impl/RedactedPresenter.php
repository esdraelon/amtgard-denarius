<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Presentation\Impl;

use Amtgard\Denarius\Domain\Statement\Presentation\StatementPresenter;
use Amtgard\Denarius\Domain\Statement\Presentation\DisplayMode;
use Amtgard\Denarius\Domain\Statement\Line\LedgerLine;

final class RedactedPresenter implements StatementPresenter
{
    public function mode(): DisplayMode
    {
        return DisplayMode::Redacted;
    }

    public function present(array $lines): array
    {
        $redacted = [];
        foreach ($lines as $line) {
            $redacted[] = $this->redact($line);
        }

        return $redacted;
    }

    private function redact(LedgerLine $line): LedgerLine
    {
        return LedgerLine::builder()
            ->postedOn($line->getPostedOn())
            ->amountCents($line->getAmountCents())
            ->category($line->getCategory())
            ->status($line->getStatus())
            ->accountName($line->getAccountName())
            ->build();
    }
}
