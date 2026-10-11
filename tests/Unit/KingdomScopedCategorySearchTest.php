<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Domain\Taxonomy\KingdomScopedCategorySearch;
use Amtgard\Denarius\Domain\Taxonomy\TransactionFlow;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Tests\Support\CategoryCatalogFixture;
use PHPUnit\Framework\TestCase;

final class KingdomScopedCategorySearchTest extends TestCase
{
    public function testSearchDelegatesWithNormalizedQuery(): void
    {
        $catalog = CategoryCatalogFixture::load();
        $search = new KingdomScopedCategorySearch($catalog);
        $kingdom = KingdomRecord::builder()->id(1)->orkKingdomId(1)->name('K')->slug('k')->build();

        $hits = $search->search($kingdom, 'Expense: rent', TransactionFlow::Expense);
        $labels = array_column($hits, 'label');

        self::assertContains('Site rental', $labels);
    }

    public function testSearchEmptyQueryReturnsFlowFilteredSet(): void
    {
        $catalog = CategoryCatalogFixture::load();
        $search = new KingdomScopedCategorySearch($catalog);
        $kingdom = KingdomRecord::builder()->id(1)->orkKingdomId(1)->name('K')->slug('k')->build();

        $hits = $search->search($kingdom, '', TransactionFlow::Expense);

        self::assertNotEmpty($hits);
        foreach ($hits as $row) {
            self::assertTrue($catalog->permitsFlow((int) $row['id'], TransactionFlow::Expense));
        }
    }

    public function testSearchOmitsSystemCategories(): void
    {
        $catalog = CategoryCatalogFixture::load();
        $search = new KingdomScopedCategorySearch($catalog);
        $kingdom = KingdomRecord::builder()->id(1)->orkKingdomId(1)->name('K')->slug('k')->build();

        $hits = $search->search($kingdom, '', TransactionFlow::Expense);
        foreach ($hits as $row) {
            self::assertFalse(str_starts_with($row['lineageKey'], 'system.'));
        }
    }
}
