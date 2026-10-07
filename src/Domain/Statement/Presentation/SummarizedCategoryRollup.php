<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Presentation;

use Amtgard\Denarius\Domain\Taxonomy\TransactionFlow;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Strategy: merge small summarized buckets into per-flow Other totals. */
final class SummarizedCategoryRollup
{
    /**
     * @param array<string, array{count: int, amount: int, flow: TransactionFlow}> $buckets
     * @return array<string, array{count: int, amount: int, flow: TransactionFlow}>
     */
    public function apply(array $buckets, int $minLines): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($buckets, $minLines): array {
            $rolled = [];
            $other = [];
            foreach ($buckets as $label => $bucket) {
                if ($bucket['count'] >= $minLines) {
                    $rolled[$label] = $bucket;
                    continue;
                }
                $flow = $bucket['flow'];
                $key = $flow->value;
                $other[$key] ??= ['count' => 0, 'amount' => 0, 'flow' => $flow];
                $other[$key]['count'] += $bucket['count'];
                $other[$key]['amount'] += $bucket['amount'];
            }
            foreach ($other as $bucket) {
                if ($bucket['count'] === 0) {
                    continue;
                }
                $rolled[$this->otherLabel($bucket['flow'])] = $bucket;
            }

            return $rolled;
        });
    }

    private function otherLabel(TransactionFlow $flow): string
    {
        return match ($flow) {
            TransactionFlow::Income => 'Other income',
            TransactionFlow::Expense => 'Other expenses',
            TransactionFlow::Transfer => 'Other transfers',
        };
    }
}
