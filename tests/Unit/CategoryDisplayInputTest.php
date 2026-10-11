<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Domain\Taxonomy\CategoryDisplayInput;
use Amtgard\Denarius\Domain\Taxonomy\TransactionFlow;
use PHPUnit\Framework\TestCase;

final class CategoryDisplayInputTest extends TestCase
{
    public function testLabelFromDisplayStripsExpensePrefix(): void
    {
        self::assertSame('Site rental', CategoryDisplayInput::labelFromDisplay('Expense: Site rental'));
    }

    public function testLabelFromDisplayStripsIncomePrefixCaseInsensitive(): void
    {
        self::assertSame('dues', CategoryDisplayInput::labelFromDisplay('  income : dues '));
    }

    public function testLabelFromDisplayReturnsPlainLabelUnchanged(): void
    {
        self::assertSame('Custom label', CategoryDisplayInput::labelFromDisplay('Custom label'));
    }

    public function testLabelFromDisplayEmptyString(): void
    {
        self::assertSame('', CategoryDisplayInput::labelFromDisplay(''));
        self::assertSame('', CategoryDisplayInput::labelFromDisplay('   '));
    }

    public function testParsePrefixedDisplayExpense(): void
    {
        $parsed = CategoryDisplayInput::parsePrefixedDisplay('Expense: Feast groceries');
        self::assertNotNull($parsed);
        self::assertSame(TransactionFlow::Expense, $parsed['flow']);
        self::assertSame('Feast groceries', $parsed['label']);
    }

    public function testParsePrefixedDisplayIncome(): void
    {
        $parsed = CategoryDisplayInput::parsePrefixedDisplay('Income: Member dues');
        self::assertNotNull($parsed);
        self::assertSame(TransactionFlow::Income, $parsed['flow']);
        self::assertSame('Member dues', $parsed['label']);
    }

    public function testParsePrefixedDisplayNonPrefixedReturnsNull(): void
    {
        self::assertNull(CategoryDisplayInput::parsePrefixedDisplay('Feast groceries'));
    }

    public function testFlowHintFromDisplayIncomePrefix(): void
    {
        self::assertSame(TransactionFlow::Income, CategoryDisplayInput::flowHintFromDisplay('Income: dues'));
    }

    public function testFlowHintFromDisplayDefaultsExpense(): void
    {
        self::assertSame(TransactionFlow::Expense, CategoryDisplayInput::flowHintFromDisplay('Feast groceries'));
    }
}
