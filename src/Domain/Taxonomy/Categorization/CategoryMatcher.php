<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy\Categorization;

/** Strategy: optional category match for one step in the matcher chain. */
interface CategoryMatcher
{
    public function match(CategorizationInput $input): ?CategoryMatch;
}
