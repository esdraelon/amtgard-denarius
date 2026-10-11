<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Domain\Taxonomy\TransactionFlow;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Tests\Support\CategoryCatalogFixture;
use PHPUnit\Framework\TestCase;

final class KingdomCategoryAssignerTest extends TestCase
{
    public function testResolveForReviewCreatesKingdomLineageFromFreeText(): void
    {
        $kingdoms = new MemoryKingdoms();
        $kingdom = $kingdoms->save(KingdomRecord::builder()->orkKingdomId(9)->name('K')->slug('k9')->build());
        $assigner = Strategies::categoryAssigner();
        $categories = CategoryCatalogFixture::load();

        $categoryId = $assigner->resolveForReview($kingdom, [
            'category_id' => '',
            'category_display' => 'Expense: Reallocate to events',
        ], -500);

        self::assertGreaterThan(0, $categoryId);
        self::assertStringStartsWith('k' . $kingdom->getId() . '.', $categories->lineageKeyForId($categoryId));
        self::assertSame('Reallocate to events', $categories->labelFor($categoryId));
    }

    public function testDisplayForKingdomSlugWithoutCustomRowUsesLabelNotUncategorized(): void
    {
        $categories = CategoryCatalogFixture::load();
        $categoryId = $categories->createWithLabel(TransactionFlow::Expense, 'Ennervate', 'k59.ennervate');
        $assigner = Strategies::categoryAssigner();

        $display = $assigner->displayFor($categoryId, TransactionFlow::Expense);

        self::assertSame('Expense: Ennervate', $display);
    }

    public function testResolveForPatternUsesCategoryIdWhenPresent(): void
    {
        $kingdoms = new MemoryKingdoms();
        $kingdom = $kingdoms->save(KingdomRecord::builder()->orkKingdomId(1)->name('K')->slug('k')->build());
        $assigner = Strategies::categoryAssigner();
        $categoryId = CategoryCatalogFixture::id('expense.site_rental');

        $resolved = $assigner->resolveForPattern($kingdom, [
            'category_id' => (string) $categoryId,
            'category_display' => 'Expense: Site rental',
        ]);

        self::assertSame($categoryId, $resolved);
    }

    public function testResolveForPatternMapsDisplayToBundledSlug(): void
    {
        $kingdoms = new MemoryKingdoms();
        $kingdom = $kingdoms->save(KingdomRecord::builder()->orkKingdomId(1)->name('K')->slug('k')->build());
        $assigner = Strategies::categoryAssigner();
        $expected = CategoryCatalogFixture::id('expense.event_supplies');

        $resolved = $assigner->resolveForPattern($kingdom, [
            'category_id' => '',
            'category_display' => 'Expense: Event supplies',
        ]);

        self::assertSame($expected, $resolved);
    }

    public function testResolveForPatternLegacyCategorySlug(): void
    {
        $kingdoms = new MemoryKingdoms();
        $kingdom = $kingdoms->save(KingdomRecord::builder()->orkKingdomId(1)->name('K')->slug('k')->build());
        $assigner = Strategies::categoryAssigner();
        $expected = CategoryCatalogFixture::id('expense.event_supplies');

        $resolved = $assigner->resolveForPattern($kingdom, [
            'category' => 'expense.event_supplies',
        ]);

        self::assertSame($expected, $resolved);
    }

    public function testResolveForPatternRequiresCategoryNameWhenEmpty(): void
    {
        $kingdoms = new MemoryKingdoms();
        $kingdom = $kingdoms->save(KingdomRecord::builder()->orkKingdomId(1)->name('K')->slug('k')->build());
        $assigner = Strategies::categoryAssigner();

        $this->expectException(\InvalidArgumentException::class);
        $assigner->resolveForPattern($kingdom, []);
    }

    public function testResolveForReviewCreatesLineageForUnknownDisplay(): void
    {
        $kingdoms = new MemoryKingdoms();
        $kingdom = $kingdoms->save(KingdomRecord::builder()->orkKingdomId(1)->name('K')->slug('k')->build());
        $assigner = Strategies::categoryAssigner();
        $categories = CategoryCatalogFixture::load();

        $categoryId = $assigner->resolveForReview($kingdom, [
            'category_display' => 'Expense: Not A Real Category Name',
        ], -100);

        self::assertStringStartsWith('k' . $kingdom->getId() . '.', $categories->lineageKeyForId($categoryId));
    }

    public function testAnchorAmountCentsParsesOrNull(): void
    {
        $assigner = Strategies::categoryAssigner();

        self::assertNull($assigner->anchorAmountCents([]));
        self::assertSame(500, $assigner->anchorAmountCents(['pattern_anchor_amount_cents' => '500']));
    }

    public function testIsUncategorizedUsesCatalogSentinel(): void
    {
        $assigner = Strategies::categoryAssigner();
        $categories = CategoryCatalogFixture::load();

        self::assertTrue($assigner->isUncategorized($categories->uncategorizedId()));
        self::assertFalse($assigner->isUncategorized(CategoryCatalogFixture::id('expense.site_rental')));
    }
}
