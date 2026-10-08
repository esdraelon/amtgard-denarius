<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy\Categorization;

use Amtgard\Denarius\Domain\Taxonomy\CategorySource;
use Amtgard\Denarius\Persistence\Repository\KingdomCategoryRule\KingdomCategoryRuleRepositoryInterface;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Chain of Responsibility: kingdom-scoped rules above shared keywords. */
final class KingdomRuleMatcher implements CategoryMatcher
{
    public function __construct(
        private readonly KingdomCategoryRuleRepositoryInterface $rules,
        private readonly KeywordRuleMatcher $keywordEngine,
    ) {
        DenariusLog::enter(__METHOD__);
    }

    public function match(CategorizationInput $input): ?CategoryMatch
    {
        return DenariusLog::trace(__METHOD__, function () use ($input): ?CategoryMatch {
            $kingdomId = $input->kingdomId();
            if ($kingdomId === null) {
                return null;
            }
            $keywordRules = [];
            foreach ($this->rules->forKingdom($kingdomId) as $record) {
                $keywordRules[] = $record->toKeywordRule();
            }
            if ($keywordRules === []) {
                return null;
            }

            return $this->keywordEngine->matchRules($keywordRules, $input, CategorySource::KingdomRule);
        });
    }
}
