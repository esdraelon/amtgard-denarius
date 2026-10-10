<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Seeds category rows from the shared taxonomy pack (and optional legacy custom slugs). */
final class CategoryCatalogSeeder
{
    /**
     * @param list<array{kingdom_id: int, slug: string, label: string, flow: string}> $legacyCustomRows
     * @return array{snapshots: list<CategorySnapshot>, lineages: array<string, int>}
     */
    public static function fromTaxonomyCatalog(
        TaxonomyCatalog $catalog,
        array $legacyCustomRows = [],
        ?\DateTimeImmutable $now = null,
    ): array {
        return DenariusLog::trace(__METHOD__, function () use ($catalog, $legacyCustomRows, $now): array {
            $now ??= new \DateTimeImmutable('now');
            $createdAt = $now->format(\DateTimeInterface::ATOM);
            $snapshots = [];
            $lineages = [];
            $nextId = 1;

            foreach ($catalog->allDefinitions() as $definition) {
                /** @var TaxonomyCategoryDefinition $definition */
                $assignable = ! str_starts_with($definition->slug, 'system.');
                $id = $nextId++;
                $snapshots[] = new CategorySnapshot(
                    $id,
                    $definition->slug,
                    $definition->label,
                    $definition->flows,
                    $definition->sensitivity,
                    $definition->summaryParentLabel,
                    $assignable,
                );
                $lineages[$definition->slug] = $id;
            }

            foreach ($legacyCustomRows as $row) {
                $slug = $row['slug'];
                if (isset($lineages[$slug])) {
                    continue;
                }
                $flow = TransactionFlow::fromStored($row['flow']);
                $id = $nextId++;
                $snapshots[] = new CategorySnapshot(
                    $id,
                    $slug,
                    $row['label'],
                    [$flow],
                    null,
                    null,
                    true,
                );
                $lineages[$slug] = $id;
            }

            return ['snapshots' => $snapshots, 'lineages' => $lineages, 'createdAt' => $createdAt];
        });
    }
}
