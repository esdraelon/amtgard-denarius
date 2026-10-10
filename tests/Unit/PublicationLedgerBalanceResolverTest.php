<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Domain\Statement\MonthWindow;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\PublicationCandidateLine;
use Amtgard\Denarius\Domain\Statement\Publication\PublicationLedgerBalanceResolver;
use Amtgard\Denarius\Tests\Support\CategoryCatalogFixture;
use PHPUnit\Framework\TestCase;

final class PublicationLedgerBalanceResolverTest extends TestCase
{
    public function testSnapshotUsesPublishedRowsThroughMonthEnd(): void
    {
        $resolver = new PublicationLedgerBalanceResolver();
        $month = new MonthWindow(2026, 9);
        $uncategorized = CategoryCatalogFixture::id('uncategorized');
        $candidates = [
            PublicationCandidateLine::builder()
                ->postedOn('2026-08-30')
                ->amountCents(1_000)
                ->categoryId($uncategorized)
                ->publishedAt('2026-08-31T00:00:00+00:00')
                ->build(),
            PublicationCandidateLine::builder()
                ->postedOn('2026-09-15')
                ->amountCents(-250)
                ->categoryId($uncategorized)
                ->publishedAt('2026-09-16T00:00:00+00:00')
                ->build(),
            PublicationCandidateLine::builder()
                ->postedOn('2026-09-20')
                ->amountCents(-250)
                ->categoryId($uncategorized)
                ->build(),
        ];

        $snapshot = $resolver->snapshotForMonth($month, $candidates);

        self::assertSame(1_000, $snapshot->openingCents);
        self::assertSame(750, $snapshot->providerEndCents);
    }
}
