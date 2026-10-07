<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Facade: manager type-ahead search over assignable taxonomy slugs. */
final class TaxonomyCategorySearch
{
    public function __construct(
        private readonly TaxonomyCatalog $catalog,
    ) {
        DenariusLog::enter(__METHOD__);
    }

    /**
     * @return list<array{slug: string, label: string, flow: string}>
     */
    public function search(string $query, ?TransactionFlow $flowFilter): array
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($method, $query, $flowFilter): array {
            $needle = strtolower(trim($query));
            $matches = [];
            foreach ($this->catalog->assignableDefinitions() as $definition) {
                if ($flowFilter !== null && !$definition->permits($flowFilter)) {
                    continue;
                }
                if ($needle !== '' && !$this->matchesNeedle($definition, $needle)) {
                    continue;
                }
                foreach ($definition->flows as $flow) {
                    if ($flowFilter !== null && $flow !== $flowFilter) {
                        continue;
                    }
                    $matches[] = [
                        'slug' => $definition->slug,
                        'label' => $definition->label,
                        'flow' => $flow->value,
                    ];
                }
            }
            usort($matches, static fn (array $a, array $b): int => strcmp($a['label'], $b['label']));
            DenariusLog::debugBranch('taxonomy_category_search', $method, [
                'result_count' => count($matches),
                'flow' => $flowFilter?->value,
            ]);

            return $matches;
        });
    }

    private function matchesNeedle(TaxonomyCategoryDefinition $definition, string $needle): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($definition, $needle): bool {
            if (str_contains(strtolower($definition->slug), $needle)) {
                return true;
            }
            if (str_contains(strtolower($definition->label), $needle)) {
                return true;
            }
            foreach ($definition->flows as $flow) {
                if (str_contains($flow->value, $needle)) {
                    return true;
                }
            }

            return false;
        });
    }
}
