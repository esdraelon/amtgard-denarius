<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Domain\Statement\MonthWindow;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Tests\Support\CategoryCatalogFixture;
use Amtgard\Denarius\Tests\Unit\Strategies;
use Amtgard\PHPUnit\AmtgardTestCase;

final class TransactionReviewMonthNavigationTest extends AmtgardTestCase
{
    public function testReviewMonthHonorsRequestedMonthWhenSet(): void
    {
        $kingdom = KingdomRecord::builder()->id(1)->orkKingdomId(1)->name('K')->slug('k')->build();
        $transactions = new MemoryTransactions();
        $transactions->upsert(TransactionRecord::builder()
            ->kingdomId(1)
            ->tellerTransactionId('oct')
            ->tellerAccountId('acc')
            ->postedOn('2026-10-03')
            ->amountCents(-100)
            ->categoryId(CategoryCatalogFixture::id('uncategorized'))
            ->build());

        $queue = Strategies::reviewQueue($transactions, new MemoryAccounts(), new \DateTimeImmutable('2026-10-15'));
        $requested = $queue->reviewMonth($kingdom, '2026-09');

        self::assertSame('2026-09', $requested->key());
        self::assertNotSame(MonthWindow::current(new \DateTimeImmutable('2026-10-15'))->key(), $requested->key());
    }
}
