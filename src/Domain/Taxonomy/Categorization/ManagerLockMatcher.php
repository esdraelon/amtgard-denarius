<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy\Categorization;

use Amtgard\Denarius\Domain\Taxonomy\CategoryCatalog;
use Amtgard\Denarius\Domain\Taxonomy\CategorySource;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Chain of Responsibility: preserve manager overrides across re-sync. */
final class ManagerLockMatcher implements CategoryMatcher
{
    private const MATCH_METHOD = self::class . '::match';

    public function __construct(private readonly CategoryCatalog $categories)
    {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function match(CategorizationInput $input): ?CategoryMatch
    {
        return DenariusLog::trace(__METHOD__, function () use ($input): ?CategoryMatch {
            if ($input->existingSource() !== CategorySource::Manager) {
                return null;
            }

            $categoryId = $input->existingCategoryId();
            if ($categoryId === null || $categoryId === 0) {
                return null;
            }

            $slug = $this->categories->lineageKeyForId($categoryId);

            DenariusLog::debugBranch('transaction_category_locked', self::MATCH_METHOD, [
                'category_id' => $categoryId,
                'category_source' => CategorySource::Manager->value,
            ]);

            return new CategoryMatch(
                $slug,
                CategorySource::Manager,
                null,
                100,
            );
        });
    }
}
