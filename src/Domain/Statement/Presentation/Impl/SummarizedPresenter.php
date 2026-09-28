<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Presentation\Impl;

use Amtgard\Denarius\Domain\Statement\Presentation\StatementPresenter;
use Amtgard\Denarius\Domain\Statement\CategoryTotal;
use Amtgard\Denarius\Domain\Statement\DisplayMode;
use Amtgard\Denarius\Domain\Statement\LedgerLine;

final class SummarizedPresenter implements StatementPresenter
{
    public function mode(): DisplayMode
    {
        return DisplayMode::Summarized;
    }

    public function present(array $lines): array
    {
        $totals = [];
        foreach ($this->buckets($lines) as $category => $bucket) {
            $totals[] = new CategoryTotal($category, $bucket['count'], $bucket['amount']);
        }

        return $totals;
    }

    /**
     * @param list<LedgerLine> $lines
     * @return array<string, array{count: int, amount: int}>
     */
    private function buckets(array $lines): array
    {
        $buckets = [];
        foreach ($lines as $line) {
            $category = $line->getCategory();
            $buckets[$category] ??= ['count' => 0, 'amount' => 0];
            $buckets[$category]['count']++;
            $buckets[$category]['amount'] += $line->getAmountCents();
        }
        ksort($buckets);

        return $buckets;
    }
}
