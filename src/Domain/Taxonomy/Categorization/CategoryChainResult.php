<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy\Categorization;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Value object: primary chain outcome plus optional sub-threshold suggestion. */
final class CategoryChainResult
{
    public function __construct(
        public readonly CategoryMatch $match,
        public readonly ?CategoryMatch $suggested,
    ) {
        DenariusLog::enter(__METHOD__);
    }
}
