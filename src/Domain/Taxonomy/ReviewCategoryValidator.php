<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Strategy: validates manager-assigned category ids for a signed amount. */
final class ReviewCategoryValidator
{
    public function __construct(
        private readonly CategoryCatalog $categories,
    ) {
        DenariusLog::enter(__METHOD__);
    }

    public function assertAssignable(int $categoryId, int $amountCents, int $kingdomId): int
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($method, $categoryId, $amountCents, $kingdomId): int {
            if ($categoryId <= 0 || $this->categories->findById($categoryId) === null) {
                DenariusLog::debugBranch('transaction_review_rejected_category', $method, [
                    'reason' => 'unknown_slug',
                    'category_id' => $categoryId,
                ]);
                throw new \InvalidArgumentException('That category is not in the taxonomy.');
            }
            $lineage = $this->categories->lineageKeyForId($categoryId);
            if (str_starts_with($lineage, 'system.')) {
                DenariusLog::debugBranch('transaction_review_rejected_category', $method, [
                    'reason' => 'system_slug',
                    'category_id' => $categoryId,
                ]);
                throw new \InvalidArgumentException('System categories cannot be assigned manually.');
            }
            if (! $this->categories->isAssignable($categoryId)) {
                DenariusLog::debugBranch('transaction_review_rejected_category', $method, [
                    'reason' => 'unknown_slug',
                    'category_id' => $categoryId,
                ]);
                throw new \InvalidArgumentException('That category is not in the taxonomy.');
            }
            $flow = TransactionFlow::defaultFromSignedCents($amountCents);
            if (! $this->categories->permitsFlow($categoryId, $flow)) {
                DenariusLog::debugBranch('transaction_review_rejected_category', $method, [
                    'reason' => 'flow_mismatch',
                    'category_id' => $categoryId,
                    'flow' => $flow->value,
                ]);
                throw new \InvalidArgumentException('That category does not match this transaction\'s direction.');
            }

            return $categoryId;
        });
    }
}
