<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Command: normalizes legacy category values on persisted transaction rows. */
final class TransactionCategorySchemaMigrator
{
    public function __construct(
        private readonly TransactionCategoryLegacyNormalizer $normalizer,
        private readonly TransactionCategoryMigrationStore $store,
    ) {
        if (DenariusLog::installedQuietly()) {
            DenariusLog::enter(__METHOD__);
        }
    }

    public function migrate(): int
    {
        $method = __METHOD__;
        $rows = $this->store->legacyRows();
        $normalized = 0;
        foreach ($rows as $row) {
            $fields = $this->normalizer->normalize((string) $row['category']);
            if ($fields['category'] !== (string) $row['category'] || $fields['providerCategory'] !== null) {
                ++$normalized;
            }
            $this->store->writeNormalized(
                $row['id'],
                $fields['category'],
                $fields['providerCategory'],
                $fields['categorySource'],
            );
        }
        if (DenariusLog::installedQuietly()) {
            DenariusLog::infoBranch('transaction_category_schema_migrated', $method, [
                'rows' => count($rows),
                'normalized' => $normalized,
            ]);
        }

        return $normalized;
    }
}
