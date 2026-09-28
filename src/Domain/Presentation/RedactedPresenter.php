<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Presentation;

use Amtgard\Denarius\Domain\DisplayMode;
use Amtgard\Denarius\Domain\LedgerLine;

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
            ->category($line->getCategory())
            ->build();
    }
}
