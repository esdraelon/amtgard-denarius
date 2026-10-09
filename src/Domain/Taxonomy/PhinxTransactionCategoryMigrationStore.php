<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy;

use Phinx\Db\Adapter\AdapterInterface;

/** Adapter: Phinx SQL access for category schema migration. */
final class PhinxTransactionCategoryMigrationStore implements TransactionCategoryMigrationStore
{
    public function __construct(private readonly AdapterInterface $adapter)
    {
    }

    public function legacyRows(): array
    {
        /** @var list<array{id: int|string, category: string}> */
        return $this->adapter->fetchAll('SELECT id, category FROM transactions');
    }

    public function writeNormalized(int|string $id, string $category, ?string $providerCategory, string $categorySource): void
    {
        $this->adapter->execute(
            'UPDATE transactions SET category = ?, provider_category = ?, category_source = ? WHERE id = ?',
            [$category, $providerCategory, $categorySource, $id],
        );
    }
}
