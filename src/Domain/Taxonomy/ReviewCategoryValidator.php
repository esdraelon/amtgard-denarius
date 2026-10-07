<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Strategy: validates manager-assigned taxonomy slugs for a signed amount. */
final class ReviewCategoryValidator
{
    public function __construct(
        private readonly TaxonomyCatalog $catalog,
    ) {
        DenariusLog::enter(__METHOD__);
    }

    public function assertAssignable(string $slug, int $amountCents): string
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($method, $slug, $amountCents): string {
            $resolved = $this->catalog->resolveSlug(trim($slug));
            if (!$this->catalog->hasSlug($resolved)) {
                DenariusLog::debugBranch('transaction_review_rejected_category', $method, [
                    'reason' => 'unknown_slug',
                    'slug' => $resolved,
                ]);
                throw new \InvalidArgumentException('That category is not in the taxonomy.');
            }
            if (str_starts_with($resolved, 'system.')) {
                DenariusLog::debugBranch('transaction_review_rejected_category', $method, [
                    'reason' => 'system_slug',
                    'slug' => $resolved,
                ]);
                throw new \InvalidArgumentException('System categories cannot be assigned manually.');
            }
            $flow = TransactionFlow::defaultFromSignedCents($amountCents);
            if (!$this->catalog->permitsFlow($resolved, $flow)) {
                DenariusLog::debugBranch('transaction_review_rejected_category', $method, [
                    'reason' => 'flow_mismatch',
                    'slug' => $resolved,
                    'flow' => $flow->value,
                ]);
                throw new \InvalidArgumentException('That category does not match this transaction\'s direction.');
            }

            return $resolved;
        });
    }
}
