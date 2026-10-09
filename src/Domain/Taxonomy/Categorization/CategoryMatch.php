<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy\Categorization;

use Amtgard\Denarius\Domain\Taxonomy\CategorySource;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Value object: one matcher outcome before confidence bands are applied. */
final class CategoryMatch
{
    public function __construct(
        public readonly string $slug,
        public readonly CategorySource $source,
        public readonly ?string $ruleId,
        public readonly int $confidence,
    ) {
        DenariusLog::enter(__METHOD__);
    }
}
