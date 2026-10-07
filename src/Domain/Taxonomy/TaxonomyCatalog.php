<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Repository: immutable in-memory view of a validated taxonomy pack. */
final class TaxonomyCatalog
{
    /**
     * @param array<string, TaxonomyCategoryDefinition> $categories
     * @param array<string, string> $retired
     * @param list<TaxonomyKeywordRule> $keywordRules
     * @param list<TaxonomyProviderHint> $providerHints
     */
    public function __construct(
        private readonly string $taxonomyVersion,
        private readonly array $categories,
        private readonly array $retired,
        private readonly array $keywordRules,
        private readonly array $providerHints,
    ) {
        DenariusLog::enter(__METHOD__);
    }

    public function taxonomyVersion(): string
    {
        return DenariusLog::trace(__METHOD__, fn (): string => $this->taxonomyVersion);
    }

    public function hasSlug(string $slug): bool
    {
        return DenariusLog::trace(__METHOD__, fn (): bool => isset($this->categories[$slug]));
    }

    public function label(string $slug): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($slug): string {
            $resolved = $this->resolveSlug($slug);
            if (! isset($this->categories[$resolved])) {
                return 'Uncategorized';
            }

            return $this->categories[$resolved]->label;
        });
    }

    public function resolveSlug(string $slug): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($slug): string {
            $current = $slug;
            $seen = [];
            while (isset($this->retired[$current])) {
                if (isset($seen[$current])) {
                    return $slug;
                }
                $seen[$current] = true;
                $current = $this->retired[$current];
            }

            return $current;
        });
    }

    /**
     * @return list<TransactionFlow>
     */
    public function flowsFor(string $slug): array
    {
        return DenariusLog::trace(__METHOD__, function () use ($slug): array {
            $resolved = $this->resolveSlug($slug);
            if (! isset($this->categories[$resolved])) {
                return [];
            }

            return $this->categories[$resolved]->flows;
        });
    }

    public function permitsFlow(string $slug, TransactionFlow $flow): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($slug, $flow): bool {
            $definition = $this->categories[$this->resolveSlug($slug)] ?? null;

            return $definition instanceof TaxonomyCategoryDefinition && $definition->permits($flow);
        });
    }

    /**
     * @return list<TaxonomyKeywordRule>
     */
    public function keywordRules(): array
    {
        return DenariusLog::trace(__METHOD__, fn (): array => $this->keywordRules);
    }

    /**
     * @return list<TaxonomyProviderHint>
     */
    public function providerHints(): array
    {
        return DenariusLog::trace(__METHOD__, fn (): array => $this->providerHints);
    }

    public static function isForbiddenMatcherTarget(string $categorySlug): bool
    {
        return DenariusLog::trace(__METHOD__, static function () use ($categorySlug): bool {
            if (str_starts_with($categorySlug, 'system.')) {
                return true;
            }

            return str_ends_with($categorySlug, '.other');
        });
    }
}
