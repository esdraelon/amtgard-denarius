<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy\Categorization;

use Amtgard\Denarius\Domain\Taxonomy\CategoryConfidence;
use Amtgard\Denarius\Domain\Taxonomy\CategorySource;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Chain of Responsibility: ordered matchers; first auto-accept wins, else best sub-threshold suggestion. */
final class CategoryMatcherChain
{
    /**
     * @param list<CategoryMatcher> $matchers
     */
    public function __construct(private readonly array $matchers)
    {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function resolve(CategorizationInput $input): CategoryChainResult
    {
        return DenariusLog::trace(__METHOD__, function () use ($input): CategoryChainResult {
            $bestSubThreshold = null;
            foreach ($this->matchers as $matcher) {
                if ($matcher instanceof FallbackMatcher) {
                    continue;
                }
                $match = $matcher->match($input);
                if ($match === null) {
                    continue;
                }
                if ($match->confidence >= CategoryConfidence::AUTO_ACCEPT) {
                    return new CategoryChainResult($match, null);
                }
                if ($match->confidence > 0 && $this->isBetterSuggestion($match, $bestSubThreshold)) {
                    $bestSubThreshold = $match;
                }
            }

            $fallback = $this->fallbackMatch($input);

            return new CategoryChainResult($fallback, $bestSubThreshold);
        });
    }

    private function fallbackMatch(CategorizationInput $input): CategoryMatch
    {
        return DenariusLog::trace(__METHOD__, function () use ($input): CategoryMatch {
            foreach ($this->matchers as $matcher) {
                if ($matcher instanceof FallbackMatcher) {
                    return $matcher->match($input) ?? new CategoryMatch('uncategorized', CategorySource::Fallback, null, 0);
                }
            }

            return new CategoryMatch('uncategorized', CategorySource::Fallback, null, 0);
        });
    }

    private function isBetterSuggestion(CategoryMatch $candidate, ?CategoryMatch $current): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($candidate, $current): bool {
            if ($current === null) {
                return true;
            }

            return $candidate->confidence > $current->confidence;
        });
    }
}
