<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Domain\Statement\MonthWindow;
use Amtgard\Denarius\Persistence\Record\AccountRecord;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Service\Ledger\TransactionReviewQueue;
use Amtgard\Denarius\Service\Ledger\TransactionReviewService;
use Amtgard\Denarius\Tests\Support\KingdomPageQueryFactory;
use Amtgard\Denarius\Tests\Support\MethodLogAssert;
use Amtgard\Denarius\Utilities\Log\BranchLogLevel;
use Amtgard\PHPUnit\AmtgardTestCase;

final class TransactionReviewTest extends AmtgardTestCase
{
    public function testPublishSetsPublishedAtAndPublicReadSeesRow(): void
    {
        class_exists(ApplicationTest::class);
        MethodLogAssert::reset();
        $kingdoms = new MemoryKingdoms();
        $accounts = new MemoryAccounts();
        $transactions = new MemoryTransactions();
        $cache = new ArrayStore();
        $now = new \DateTimeImmutable('2026-10-01T12:00:00+00:00');
        $kingdom = $kingdoms->save(KingdomRecord::builder()->orkKingdomId(1)->name('Test')->slug('test')->build());
        $accounts->save(AccountRecord::builder()->kingdomId((int) $kingdom->getId())->tellerAccountId('acc')->name('Checking')->published(true)->build());
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
            ->publishableAfter('2026-09-05T00:00:00+00:00')
            ->build());

        $reviews = new TransactionReviewService($transactions, $accounts, Strategies::months($cache), $now);
        $reviews->publish($kingdom, 't1');

        $published = $transactions->findByTellerTransactionId('t1');
        $this->assertSame('2026-10-01T12:00:00+00:00', $published?->getPublishedAt());
        $statement = KingdomPageQueryFactory::publicRead($transactions, $accounts)->statement($kingdom, new MonthWindow(2026, 9));
        $this->assertCount(1, $statement->rows);
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Info, 'transaction_review_published', TransactionReviewService::class . '::publish');
    }

    public function testWithholdClearsPublishedAt(): void
    {
        class_exists(ApplicationTest::class);
        MethodLogAssert::reset();
        $kingdoms = new MemoryKingdoms();
        $accounts = new MemoryAccounts();
        $transactions = new MemoryTransactions();
        $now = new \DateTimeImmutable('2026-10-01T12:00:00+00:00');
        $kingdom = $kingdoms->save(KingdomRecord::builder()->orkKingdomId(1)->name('Test')->slug('test')->build());
        $accounts->save(AccountRecord::builder()->kingdomId((int) $kingdom->getId())->tellerAccountId('acc')->name('Checking')->published(true)->build());
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
            ->publishedAt('2026-09-04T00:00:00+00:00')
            ->build());

        $reviews = new TransactionReviewService($transactions, $accounts, Strategies::months(), $now);
        $reviews->withhold($kingdom, 't1');

        $this->assertNull($transactions->findByTellerTransactionId('t1')?->getPublishedAt());
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Info, 'transaction_review_withheld', TransactionReviewService::class . '::withhold');
    }

    public function testPublishRejectsEmbargoedRow(): void
    {
        class_exists(ApplicationTest::class);
        MethodLogAssert::reset();
        $kingdoms = new MemoryKingdoms();
        $accounts = new MemoryAccounts();
        $transactions = new MemoryTransactions();
        $now = new \DateTimeImmutable('2026-09-06T12:00:00+00:00');
        $kingdom = $kingdoms->save(KingdomRecord::builder()->orkKingdomId(1)->name('Test')->slug('test')->build());
        $accounts->save(AccountRecord::builder()->kingdomId((int) $kingdom->getId())->tellerAccountId('acc')->name('Checking')->published(true)->build());
        $transactions->upsert(TransactionRecord::builder()
            ->kingdomId((int) $kingdom->getId())
            ->tellerTransactionId('t1')
            ->tellerAccountId('acc')
            ->postedOn('2026-09-02')
            ->amountCents(-100)
            ->category('general')
            ->publishableAfter('2026-09-10T00:00:00+00:00')
            ->build());

        $reviews = new TransactionReviewService($transactions, $accounts, Strategies::months(), $now);
        try {
            $reviews->publish($kingdom, 't1');
            $this->fail('Expected embargo rejection.');
        } catch (\InvalidArgumentException) {
            $this->assertNull($transactions->findByTellerTransactionId('t1')?->getPublishedAt());
        }
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Debug, 'transaction_review_rejected_embargo', TransactionReviewService::class . '::publish');
    }

    public function testQueueListsPendingEmbargoedAndPublished(): void
    {
        class_exists(ApplicationTest::class);
        MethodLogAssert::reset();
        $kingdoms = new MemoryKingdoms();
        $accounts = new MemoryAccounts();
        $transactions = new MemoryTransactions();
        $now = new \DateTimeImmutable('2026-09-06T12:00:00+00:00');
        $kingdom = $kingdoms->save(KingdomRecord::builder()->orkKingdomId(1)->name('Test')->slug('test')->build());
        $accounts->save(AccountRecord::builder()->kingdomId((int) $kingdom->getId())->tellerAccountId('acc')->name('Checking')->published(true)->build());
        $transactions->upsert(TransactionRecord::builder()
            ->kingdomId((int) $kingdom->getId())
            ->tellerTransactionId('pending')
            ->tellerAccountId('acc')
            ->postedOn('2026-09-01')
            ->amountCents(-50)
            ->category('general')
            ->publishableAfter('2026-09-05T00:00:00+00:00')
            ->build());
        $transactions->upsert(TransactionRecord::builder()
            ->kingdomId((int) $kingdom->getId())
            ->tellerTransactionId('embargo')
            ->tellerAccountId('acc')
            ->postedOn('2026-09-04')
            ->amountCents(-60)
            ->category('general')
            ->publishableAfter('2026-09-10T00:00:00+00:00')
            ->build());
        $transactions->upsert(TransactionRecord::builder()
            ->kingdomId((int) $kingdom->getId())
            ->tellerTransactionId('live')
            ->tellerAccountId('acc')
            ->postedOn('2026-08-20')
            ->amountCents(-70)
            ->category('general')
            ->publishedAt('2026-08-21T00:00:00+00:00')
            ->build());

        $rows = Strategies::reviewQueue($transactions, $accounts, $now)->rowsForManage($kingdom);
        $this->assertCount(3, $rows);
        $statuses = array_column($rows, 'status');
        $this->assertContains('pending', $statuses);
        $this->assertContains('embargoed', $statuses);
        $this->assertContains('published', $statuses);
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Debug, 'transaction_review_queue_loaded', TransactionReviewQueue::class . '::rowsForManage');
    }
}
