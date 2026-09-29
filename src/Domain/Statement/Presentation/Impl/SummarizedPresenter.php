<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Presentation\Impl;

use Amtgard\Denarius\Domain\Statement\Presentation\StatementPresenter;
use Amtgard\Denarius\Domain\Statement\Line\CategoryTotal;
use Amtgard\Denarius\Domain\Statement\Presentation\DisplayMode;
use Amtgard\Denarius\Domain\Statement\Line\LedgerLine;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class SummarizedPresenter implements StatementPresenter
{
    public function mode(): DisplayMode
    {
        return DenariusLog::trace(__METHOD__, function (): DisplayMode {
            return DisplayMode::Summarized;
        });
    }

    public function present(array $lines): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($lines): array {
            $totals = [];
            foreach ($this->buckets($lines) as $category => $bucket) {
                $totals[] = new CategoryTotal($category, $bucket['count'], $bucket['amount']);
            }

            return $totals;
        });
    }

    /**
     * @param list<LedgerLine> $lines
     * @return array<string, array{count: int, amount: int}>
     */
    private function buckets(array $lines): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($lines): array {
            $buckets = [];
            foreach ($lines as $line) {
                $category = $line->getCategory();
                $buckets[$category] ??= ['count' => 0, 'amount' => 0];
                $buckets[$category]['count']++;
                $buckets[$category]['amount'] += $line->getAmountCents();
            }
            ksort($buckets);

            return $buckets;
        });
    }
}
