<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Domain\Taxonomy\TaxonomyCategoryPicker;
use Amtgard\Denarius\Tests\Support\CategorizationArrange;
use Amtgard\PHPUnit\AmtgardTestCase;
use PHPUnit\Framework\Attributes\Test;

final class TaxonomyCategoryPickerTest extends AmtgardTestCase
{
    private TaxonomyCategoryPicker $picker;

    protected function setUp(): void
    {
        $this->picker = new TaxonomyCategoryPicker(CategorizationArrange::bundledCatalog());
    }

    #[Test]
    public function resolveForPatternUsesHiddenSlugWhenPresent(): void
    {
        $slug = $this->picker->resolveForPattern('expense.event_supplies', '');

        self::assertSame('expense.event_supplies', $slug);
    }

    #[Test]
    public function resolveForPatternUsesDisplayLabelWhenSlugMissing(): void
    {
        $slug = $this->picker->resolveForPattern('', 'Expense: Event supplies');

        self::assertSame('expense.event_supplies', $slug);
    }

    #[Test]
    public function resolveForReviewRejectsUnknownDisplay(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->picker->resolveForReview('', 'Expense: Not A Real Category Name', -100);
    }

    #[Test]
    public function resolveForPatternDoesNotMapExpenseTransferLabelToTransferCategory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->picker->resolveForPattern('', 'Expense: Transfer');
    }
}
