<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain;

final class MonthStatementBuilder
{
    /**
     * @param list<LedgerLine> $lines
     */
    public function build(array $lines, DisplayMode $mode, MonthWindow $month): MonthStatement
    {
        $inMonth = [];
        foreach ($lines as $line) {
            if ($month->contains($line->getPostedOn())) {
                $inMonth[] = $line;
            }
        }

        $rows = match ($mode) {
            DisplayMode::All => $inMonth,
            DisplayMode::Redacted => $this->redact($inMonth),
            DisplayMode::Summarized => $this->summarize($inMonth),
        };

        return new MonthStatement($mode, $month, $rows);
    }

    /**
     * @param list<LedgerLine> $lines
     * @return list<LedgerLine>
     */
    private function redact(array $lines): array
    {
        $redacted = [];
        foreach ($lines as $line) {
            $redacted[] = LedgerLine::builder()
                ->postedOn($line->getPostedOn())
                ->category($line->getCategory())
                ->build();
        }

        return $redacted;
    }

    /**
     * @param list<LedgerLine> $lines
     * @return list<CategoryTotal>
     */
    private function summarize(array $lines): array
    {
        $buckets = [];
        foreach ($lines as $line) {
            $category = $line->getCategory();
            if (!isset($buckets[$category])) {
                $buckets[$category] = ['count' => 0, 'amount' => 0];
            }
            $buckets[$category]['count']++;
            $buckets[$category]['amount'] += $line->getAmountCents();
        }

        ksort($buckets);
        $totals = [];
        foreach ($buckets as $category => $bucket) {
            $totals[] = new CategoryTotal($category, $bucket['count'], $bucket['amount']);
        }

        return $totals;
    }
}
