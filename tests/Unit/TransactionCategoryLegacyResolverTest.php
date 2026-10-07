<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Domain\Taxonomy\CategorySource;
use Amtgard\Denarius\Domain\Taxonomy\TransactionCategoryLegacyResolver;
use Amtgard\PHPUnit\AmtgardTestCase;

final class TransactionCategoryLegacyResolverTest extends AmtgardTestCase
{
    private TransactionCategoryLegacyResolver $resolver;

    protected function setUp(): void
    {
        $pack = dirname(__DIR__, 2) . '/data/taxonomy/taxonomy.json';
        $this->resolver = TransactionCategoryLegacyResolver::fromTaxonomyJson($pack);
    }

    public function testGeneralBecomesUncategorizedWithoutProviderHint(): void
    {
        $normalized = $this->resolver->normalizeStoredCategory('general');
        $this->assertSame('uncategorized', $normalized['category']);
        $this->assertNull($normalized['providerCategory']);
        $this->assertSame(CategorySource::Fallback->value, $normalized['categorySource']);
    }

    public function testUnknownProviderStringMovesToProviderCategory(): void
    {
        $normalized = $this->resolver->normalizeStoredCategory('FOOD_AND_DRINK');
        $this->assertSame('uncategorized', $normalized['category']);
        $this->assertSame('FOOD_AND_DRINK', $normalized['providerCategory']);
    }

    public function testKnownSlugIsPreserved(): void
    {
        $normalized = $this->resolver->normalizeStoredCategory('expense.site_rental');
        $this->assertSame('expense.site_rental', $normalized['category']);
        $this->assertNull($normalized['providerCategory']);
    }

}
