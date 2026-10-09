<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy\Categorization;

use Amtgard\Denarius\Domain\Taxonomy\CategorySource;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Chain of Responsibility: terminal uncategorized slug when nothing auto-accepts. */
final class FallbackMatcher implements CategoryMatcher
{
    public function __construct()
    {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function match(CategorizationInput $input): ?CategoryMatch
    {
        return DenariusLog::trace(__METHOD__, function (): CategoryMatch {
            return new CategoryMatch(
                'uncategorized',
                CategorySource::Fallback,
                null,
                0,
            );
        });
    }
}
