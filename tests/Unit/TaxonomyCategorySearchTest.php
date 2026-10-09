<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Domain\Taxonomy\TaxonomyCategorySearch;
use Amtgard\Denarius\Domain\Taxonomy\TransactionFlow;
use Amtgard\Denarius\Tests\Support\CategorizationArrange;
use Amtgard\Denarius\Tests\Support\MethodLogAssert;
use Amtgard\Denarius\Utilities\Log\BranchLogLevel;
use Amtgard\PHPUnit\AmtgardTestCase;

final class TaxonomyCategorySearchTest extends AmtgardTestCase
{
    public function testSearchFiltersByFlowAndQuery(): void
    {
        $search = new TaxonomyCategorySearch(CategorizationArrange::bundledCatalog());
        MethodLogAssert::reset();
        $results = $search->search('site', TransactionFlow::Expense);
        $this->assertNotEmpty($results);
        foreach ($results as $row) {
            $this->assertSame('expense', $row['flow']);
            $this->assertStringContainsString('site', strtolower($row['slug'] . $row['label']));
        }
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Debug, 'taxonomy_category_search', TaxonomyCategorySearch::class . '::search');
    }

    public function testSearchOmitsSystemSlugs(): void
    {
        $search = new TaxonomyCategorySearch(CategorizationArrange::bundledCatalog());
        foreach ($search->search('bank', null) as $row) {
            $this->assertStringNotContainsString('system.', $row['slug']);
        }
    }
}
