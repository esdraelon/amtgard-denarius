<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Domain\Taxonomy\TaxonomyCatalog;
use Amtgard\Denarius\Domain\Taxonomy\TaxonomyCatalogLoader;
use Amtgard\Denarius\Domain\Taxonomy\TransactionFlow;
use Amtgard\PHPUnit\AmtgardTestCase;

final class TaxonomyCatalogTest extends AmtgardTestCase
{
    private TaxonomyCatalog $catalog;

    protected function setUp(): void
    {
        $loader = new TaxonomyCatalogLoader(dirname(__DIR__, 2), 'data/taxonomy');
        $this->catalog = $loader->load();
    }

    public function testRetiredSlugMapsForward(): void
    {
        $this->assertSame('uncategorized', $this->catalog->resolveSlug('expense.general'));
        $this->assertSame('Uncategorized', $this->catalog->label('expense.general'));
    }

    public function testRetiredCycleReturnsOriginalSlug(): void
    {
        $categories = [
            'a' => new \Amtgard\Denarius\Domain\Taxonomy\TaxonomyCategoryDefinition(
                'a',
                'A',
                [\Amtgard\Denarius\Domain\Taxonomy\TransactionFlow::Expense],
            ),
        ];
        $catalog = new TaxonomyCatalog('taxonomy/v1', $categories, ['x' => 'y', 'y' => 'x'], [], []);
        $this->assertSame('loop', $catalog->resolveSlug('loop'));
    }

    public function testUnknownSlugLabelFallback(): void
    {
        $this->assertSame('Uncategorized', $this->catalog->label('not.a.real.slug'));
    }

    public function testFlowsForUnknownSlugReturnsEmpty(): void
    {
        $this->assertSame([], $this->catalog->flowsFor('missing.slug'));
    }

    public function testPermitsFlowFalseForUnknownSlug(): void
    {
        $this->assertFalse($this->catalog->permitsFlow('missing.slug', TransactionFlow::Expense));
    }

    public function testForbiddenMatcherTargets(): void
    {
        $this->assertTrue(TaxonomyCatalog::isForbiddenMatcherTarget('system.bank_verification'));
        $this->assertTrue(TaxonomyCatalog::isForbiddenMatcherTarget('expense.other'));
        $this->assertFalse(TaxonomyCatalog::isForbiddenMatcherTarget('expense.bank_fees'));
    }

    public function testGoldenFixtureFileParses(): void
    {
        $path = dirname(__DIR__, 2) . '/data/taxonomy/fixtures/golden.json';
        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('taxonomy/v1', $payload['taxonomyVersion']);
        $this->assertNotEmpty($payload['cases']);
    }

    public function testProviderHintsAndKeywordRulesLoaded(): void
    {
        $this->assertNotEmpty($this->catalog->keywordRules());
        $this->assertNotEmpty($this->catalog->providerHints());
        $this->assertTrue($this->catalog->permitsFlow('expense.bank_fees', TransactionFlow::Expense));
    }
}
