<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Presentation;

use Amtgard\Denarius\Domain\Statement\Line\CategoryTotal;
use Amtgard\Denarius\Domain\Statement\Line\LedgerLine;
use Amtgard\Denarius\Domain\Taxonomy\TransactionFlow;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Strategy: summarized tier buckets, flow sections, and net excluding transfers. */
final class SummarizedStatementComposer
{
    public function __construct(
        private readonly SummarizedCategoryRollup $rollup = new SummarizedCategoryRollup(),
    ) {
        DenariusLog::enter(__METHOD__);
    }

    /**
     * @param list<LedgerLine> $lines
     * @return list<CategoryTotal>
     */
    public function compose(array $lines, int $minLines): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($lines, $minLines): array {
            $buckets = $this->rawBuckets($lines);
            $rolled = $this->rollup->apply($buckets, $minLines);

            return $this->orderedTotals($rolled);
        });
    }

    /**
     * @param list<LedgerLine> $lines
     * @return array<string, array{count: int, amount: int, flow: TransactionFlow}>
     */
    private function rawBuckets(array $lines): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($lines): array {
            $buckets = [];
            foreach ($lines as $line) {
                $label = $line->getCategory();
                $flow = TransactionFlow::fromStored($line->getCategoryFlow())
                    ?? TransactionFlow::defaultFromSignedCents($line->getAmountCents());
                $buckets[$label] ??= ['count' => 0, 'amount' => 0, 'flow' => $flow];
                $buckets[$label]['count']++;
                $buckets[$label]['amount'] += $line->getAmountCents();
            }

            return $buckets;
        });
    }

    /**
     * @param array<string, array{count: int, amount: int, flow: TransactionFlow}> $buckets
     * @return list<CategoryTotal>
     */
    private function orderedTotals(array $buckets): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($buckets): array {
            $byFlow = [
                TransactionFlow::Income->value => [],
                TransactionFlow::Expense->value => [],
                TransactionFlow::Transfer->value => [],
            ];
            foreach ($buckets as $label => $bucket) {
                $byFlow[$bucket['flow']->value][$label] = $bucket;
            }

            $totals = [];
            $netCents = 0;
            foreach ([TransactionFlow::Income, TransactionFlow::Expense, TransactionFlow::Transfer] as $flow) {
                $section = $byFlow[$flow->value];
                ksort($section);
                foreach ($section as $label => $bucket) {
                    $totals[] = new CategoryTotal($label, $bucket['count'], $bucket['amount'], $flow);
                    if ($flow !== TransactionFlow::Transfer) {
                        $netCents += $bucket['amount'];
                    }
                }
            }
            if ($totals !== []) {
                $totals[] = CategoryTotal::net($netCents);
            }

            return $totals;
        });
    }
}
