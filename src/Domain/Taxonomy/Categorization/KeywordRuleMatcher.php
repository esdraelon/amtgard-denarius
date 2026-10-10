<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy\Categorization;

use Amtgard\Denarius\Domain\Taxonomy\CategorySource;
use Amtgard\Denarius\Domain\Taxonomy\PatternGlob;
use Amtgard\Denarius\Domain\Taxonomy\TaxonomyCatalog;
use Amtgard\Denarius\Domain\Taxonomy\TaxonomyKeywordRule;
use Amtgard\Denarius\Domain\Taxonomy\TransactionFlow;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Chain of Responsibility: shared keywords.json rules on normalized text fields. */
final class KeywordRuleMatcher implements CategoryMatcher
{
    public function __construct(private readonly TaxonomyCatalog $catalog)
    {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function match(CategorizationInput $input): ?CategoryMatch
    {
        return DenariusLog::trace(__METHOD__, function () use ($input): ?CategoryMatch {
            return $this->matchRules($this->catalog->keywordRules(), $input, CategorySource::SharedRule);
        });
    }

    /**
     * @param list<TaxonomyKeywordRule> $rules
     */
    public function matchRules(array $rules, CategorizationInput $input, CategorySource $source): ?CategoryMatch
    {
        return DenariusLog::trace(__METHOD__, function () use ($rules, $input, $source): ?CategoryMatch {
            $best = null;
            $bestPatternLength = -1;
            $bestOrder = PHP_INT_MAX;
            $order = 0;
            foreach ($rules as $rule) {
                ++$order;
                if (! $this->flowAllows($rule, $input->defaultFlow())) {
                    continue;
                }
                if (! $this->textMatches($rule, $input)) {
                    continue;
                }

                $patternLength = $this->patternLength($rule);
                if ($best === null || $rule->confidence > $best->confidence) {
                    $best = $rule;
                    $bestPatternLength = $patternLength;
                    $bestOrder = $order;
                    continue;
                }
                if ($rule->confidence < $best->confidence) {
                    continue;
                }
                if ($patternLength > $bestPatternLength) {
                    $best = $rule;
                    $bestPatternLength = $patternLength;
                    $bestOrder = $order;
                    continue;
                }
                if ($patternLength === $bestPatternLength && $order < $bestOrder) {
                    $best = $rule;
                    $bestOrder = $order;
                }
            }

            if ($best === null) {
                return null;
            }

            return new CategoryMatch(
                $best->category,
                $source,
                $best->id,
                $best->confidence,
            );
        });
    }

    private function flowAllows(TaxonomyKeywordRule $rule, TransactionFlow $defaultFlow): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($rule, $defaultFlow): bool {
            if (in_array($defaultFlow, $rule->flows, true)) {
                return true;
            }

            return count($rule->flows) === 1 && $rule->flows[0] === TransactionFlow::Transfer;
        });
    }

    private function textMatches(TaxonomyKeywordRule $rule, CategorizationInput $input): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($rule, $input): bool {
            foreach ($rule->fields as $field) {
                $text = $field === 'counterparty' ? $input->normalizedCounterparty() : $input->normalizedDescription();
                if ($text === '') {
                    continue;
                }
                if ($this->matchField($rule, $text)) {
                    return true;
                }
            }

            return false;
        });
    }

    private function matchField(TaxonomyKeywordRule $rule, string $text): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($rule, $text): bool {
            return match ($rule->matchType) {
                'regex' => $rule->regexPattern !== ''
                    && preg_match('/' . $rule->regexPattern . '/', $text) === 1,
                'token' => $rule->token !== '' && PatternGlob::matchesInText($rule->token, $text),
                'anyOf' => $this->matchesAnyOf($rule->anyOfTokens, $text),
                default => false,
            };
        });
    }

    /**
     * @param list<string> $tokens
     */
    private function matchesAnyOf(array $tokens, string $text): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($tokens, $text): bool {
            foreach ($tokens as $token) {
                if ($token !== '' && PatternGlob::matchesInText($token, $text)) {
                    return true;
                }
            }

            return false;
        });
    }

    private function patternLength(TaxonomyKeywordRule $rule): int
    {
        return DenariusLog::trace(__METHOD__, function () use ($rule): int {
            return match ($rule->matchType) {
                'regex' => strlen($rule->regexPattern),
                'token' => strlen($rule->token),
                'anyOf' => array_reduce(
                    $rule->anyOfTokens,
                    static fn (int $max, string $token): int => max($max, strlen($token)),
                    0,
                ),
                default => 0,
            };
        });
    }
}
