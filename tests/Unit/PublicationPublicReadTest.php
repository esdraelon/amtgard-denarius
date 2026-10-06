<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Domain\Statement\MonthWindow;
use Amtgard\Denarius\Persistence\Record\AccountRecord;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Tests\Support\KingdomPageQueryFactory;
use Amtgard\PHPUnit\AmtgardTestCase;

final class PublicationPublicReadTest extends AmtgardTestCase
{
    public function testPublicStatementOmitsUnpublishedTransactions(): void
    {
        $kingdoms = new MemoryKingdoms();
        $accounts = new MemoryAccounts();
        $transactions = new MemoryTransactions();
        $kingdom = $kingdoms->save(KingdomRecord::builder()->orkKingdomId(1)->name('Test')->slug('test')->build());
        $accounts->save(AccountRecord::builder()->kingdomId((int) $kingdom->getId())->tellerAccountId('acc')->name('Checking')->type('depository')->published(true)->build());
        $transactions->upsert(TransactionRecord::builder()
            ->kingdomId((int) $kingdom->getId())
            ->tellerTransactionId('t1')
            ->tellerAccountId('acc')
            ->postedOn('2026-09-02')
            ->amountCents(-100)
            ->category('general')
            ->description('supplies')
            ->counterparty('Shop')
            ->status('posted')
            ->build());
        $transactions->upsert(TransactionRecord::builder()
            ->kingdomId((int) $kingdom->getId())
            ->tellerTransactionId('t2')
            ->tellerAccountId('acc')
            ->postedOn('2026-09-03')
            ->amountCents(-200)
            ->category('general')
            ->description('fuel')
            ->counterparty('Station')
            ->status('posted')
            ->publishedAt('2026-09-04T00:00:00+00:00')
            ->build());

        $query = KingdomPageQueryFactory::publicRead($transactions, $accounts);
        $statement = $query->statement($kingdom, new MonthWindow(2026, 9));
        $this->assertCount(1, $statement->rows);
    }
}
