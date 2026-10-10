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
}
