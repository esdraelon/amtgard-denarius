<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Support;

use Amtgard\Denarius\Domain\Taxonomy\CategoryCatalog;

final class CategoryCatalogFixture
{
    private static ?MemoryCategoryCatalog $catalog = null;

    public static function load(): MemoryCategoryCatalog
    {
        self::$catalog ??= MemoryCategoryCatalog::seeded(CategorizationArrange::bundledCatalog());

        return self::$catalog;
    }

    public static function asInterface(): CategoryCatalog
    {
        return self::load();
    }

    public static function id(string $lineageKey): int
    {
        $legacy = [
            'general' => 'uncategorized',
            'office' => 'expense.bank_fees',
            'dining' => 'expense.feast_groceries',
            'fuel' => 'expense.feast_groceries',
            'FOOD_AND_DRINK' => 'uncategorized',
        ];
        $resolved = $legacy[$lineageKey] ?? $lineageKey;

        return self::load()->idForLineage($resolved);
    }
}
