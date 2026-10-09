<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Domain\Taxonomy\TransactionCategoryLegacyNormalizer;
use Amtgard\Denarius\Domain\Taxonomy\TransactionCategoryMigrationStore;
use Amtgard\Denarius\Domain\Taxonomy\TransactionCategorySchemaMigrator;
use Amtgard\Denarius\Tests\Support\MethodLogAssert;
use Amtgard\Denarius\Utilities\Log\BranchLogLevel;
use Amtgard\PHPUnit\AmtgardTestCase;

final class TransactionCategorySchemaMigratorTest extends AmtgardTestCase
{
    protected function setUp(): void
    {
        MethodLogAssert::reset();
    }

    public function testMigratorLogsNormalizedCounts(): void
    {
        $store = new class implements TransactionCategoryMigrationStore {
            /** @var list<array{id: int, category: string, providerCategory: ?string, categorySource: string}> */
            public array $writes = [];

            public function legacyRows(): array
            {
                return [
                    ['id' => 1, 'category' => 'general'],
                    ['id' => 2, 'category' => 'expense.site_rental'],
                ];
            }

            public function writeNormalized(int|string $id, string $category, ?string $providerCategory, string $categorySource): void
            {
                $this->writes[] = [
                    'id' => (int) $id,
                    'category' => $category,
                    'providerCategory' => $providerCategory,
                    'categorySource' => $categorySource,
                ];
            }
        };

        $pack = dirname(__DIR__, 2) . '/data/taxonomy/taxonomy.json';
        $migrator = new TransactionCategorySchemaMigrator(
            TransactionCategoryLegacyNormalizer::fromTaxonomyJson($pack),
            $store,
        );
        $this->assertSame(1, $migrator->migrate());
        $this->assertSame('uncategorized', $store->writes[0]['category']);
        $this->assertSame('expense.site_rental', $store->writes[1]['category']);
        MethodLogAssert::assertBranchLogged(
            BranchLogLevel::Info,
            'transaction_category_schema_migrated',
            TransactionCategorySchemaMigrator::class . '::migrate',
        );
    }
}
