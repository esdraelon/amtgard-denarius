<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Domain\Taxonomy\PhinxTransactionCategoryMigrationStore;
use Amtgard\PHPUnit\AmtgardTestCase;
use Phinx\Db\Adapter\AdapterInterface;

final class PhinxTransactionCategoryMigrationStoreTest extends AmtgardTestCase
{
    public function testLegacyRowsAndWriteNormalized(): void
    {
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->expects($this->once())
            ->method('fetchAll')
            ->with('SELECT id, category FROM transactions')
            ->willReturn([['id' => 3, 'category' => 'general']]);
        $adapter->expects($this->once())
            ->method('execute')
            ->with(
                'UPDATE transactions SET category = ?, provider_category = ?, category_source = ? WHERE id = ?',
                ['uncategorized', null, 'fallback', 3],
            );

        $store = new PhinxTransactionCategoryMigrationStore($adapter);
        $this->assertSame([['id' => 3, 'category' => 'general']], $store->legacyRows());
        $store->writeNormalized(3, 'uncategorized', null, 'fallback');
    }
}
