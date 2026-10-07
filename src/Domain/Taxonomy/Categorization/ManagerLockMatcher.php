<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy\Categorization;

use Amtgard\Denarius\Domain\Taxonomy\CategorySource;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Chain of Responsibility: preserve manager overrides across re-sync. */
final class ManagerLockMatcher implements CategoryMatcher
{
    private const MATCH_METHOD = self::class . '::match';

    public function __construct()
    {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function match(CategorizationInput $input): ?CategoryMatch
    {
        return DenariusLog::trace(__METHOD__, function () use ($input): ?CategoryMatch {
            if ($input->existingSource() !== CategorySource::Manager) {
                return null;
            }

            $slug = $input->existingCategory();
            if ($slug === null || $slug === '') {
                return null;
            }

            DenariusLog::debugBranch('transaction_category_locked', self::MATCH_METHOD, [
                'category' => $slug,
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
