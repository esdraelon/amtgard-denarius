<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy\Categorization;

use Amtgard\Denarius\Domain\Taxonomy\CategorySource;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Value object: persisted category block after auto-accept and suggest bands. */
final class CategoryDecision
{
    public function __construct(
        public readonly string $category,
        public readonly CategorySource $source,
        public readonly ?string $ruleId,
        public readonly int $confidence,
        public readonly ?string $suggestedSlug,
        public readonly string $taxonomyVersion,
    ) {
        DenariusLog::enter(__METHOD__);
    }
}
