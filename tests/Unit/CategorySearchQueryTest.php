<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Domain\Taxonomy\CategorySearchQuery;
use Amtgard\Denarius\Domain\Taxonomy\KingdomScopedCategorySearch;
use Amtgard\Denarius\Domain\Taxonomy\TransactionFlow;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Tests\Support\CategoryCatalogFixture;
use PHPUnit\Framework\TestCase;

final class CategorySearchQueryTest extends TestCase
{
    public function testNormalizeStripsDisplayFlowPrefix(): void
    {
        self::assertSame('Site rental', CategorySearchQuery::normalize('Expense: Site rental'));
        self::assertSame('dues', CategorySearchQuery::normalize('  income : dues'));
    }

    public function testKingdomSearchFiltersByFlowAndIgnoresPrefixInQuery(): void
    {
        $catalog = CategoryCatalogFixture::load();
        $incomeId = $catalog->createWithLabel(TransactionFlow::Income, 'Zephyr pledge', 'income.zephyr_pledge_search');
        $expenseId = CategoryCatalogFixture::id('expense.site_rental');
        $search = new KingdomScopedCategorySearch($catalog);
        $kingdom = KingdomRecord::builder()->id(1)->orkKingdomId(1)->name('K')->slug('k')->build();

        $expenseHits = $search->search($kingdom, 'Expense: site', TransactionFlow::Expense);
        $labels = array_column($expenseHits, 'label');
        self::assertContains('Site rental', $labels);
        self::assertNotContains('Zephyr pledge', $labels);

        $incomeHits = $search->search($kingdom, 'Income: zephyr', TransactionFlow::Income);
        self::assertContains($incomeId, array_column($incomeHits, 'id'));
        self::assertNotContains($expenseId, array_column($incomeHits, 'id'));
    }
}
