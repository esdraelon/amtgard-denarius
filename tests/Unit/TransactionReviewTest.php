<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Domain\Statement\MonthWindow;
use Amtgard\Denarius\Domain\Statement\Publication\PublicationFlags;
use Amtgard\Denarius\Domain\Taxonomy\CategorySource;
use Amtgard\Denarius\Domain\Taxonomy\ReviewCategoryValidator;
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
        $kingdoms = new MemoryKingdoms();
        $accounts = new MemoryAccounts();
        $transactions = new MemoryTransactions();
        $cache = new ArrayStore();
        $now = new \DateTimeImmutable('2026-10-01T12:00:00+00:00');
        $kingdom = $kingdoms->save(KingdomRecord::builder()->orkKingdomId(1)->name('Test')->slug('test')->displayMode('redacted')->build());
        $accounts->save(AccountRecord::builder()->kingdomId((int) $kingdom->getId())->tellerAccountId('acc')->name('Checking')->published(true)->build());
        $transactions->upsert(TransactionRecord::builder()
            ->kingdomId((int) $kingdom->getId())
            ->tellerTransactionId('t1')
            ->tellerAccountId('acc')
            ->postedOn('2026-09-02')
            ->amountCents(-100)
            ->category('expense.feast_groceries')
            ->categorySource(CategorySource::SharedRule->value)
            ->categoryConfidence(85)
            ->description('supplies')
            ->counterparty('Shop')
            ->status('posted')
            ->publishableAfter('2026-09-05T00:00:00+00:00')
            ->build());

        $reviews = Strategies::reviewService($transactions, $accounts, Strategies::months($cache), $now);
        MethodLogAssert::reset();
        $reviews->publish($kingdom, 't1');

        $published = $transactions->findByTellerTransactionId('t1');
        $this->assertSame('2026-10-01T12:00:00+00:00', $published?->getPublishedAt());
        $statement = KingdomPageQueryFactory::publicRead($transactions, $accounts)->statement($kingdom, new MonthWindow(2026, 9));
        $this->assertCount(1, $statement->rows);
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Info, 'transaction_review_published', TransactionReviewService::class . '::publish');
    }

    public function testAutoAcceptedCategoryPublishesWithoutManagerOverride(): void
    {
        class_exists(ApplicationTest::class);
        $kingdom = $this->kingdomWithAccount();
        $transactions = $kingdom['transactions'];
        $reviews = Strategies::reviewService($transactions, $kingdom['accounts'], Strategies::months(), new \DateTimeImmutable('2026-10-01'));
        $transactions->upsert(TransactionRecord::builder()
            ->kingdomId((int) $kingdom['record']->getId())
            ->tellerTransactionId('auto')
            ->tellerAccountId('acc')
            ->postedOn('2026-09-02')
            ->amountCents(-200)
            ->category('expense.site_rental')
            ->categorySource(CategorySource::SharedRule->value)
            ->categoryConfidence(72)
            ->publishableAfter('2026-09-01T00:00:00+00:00')
            ->build());
        MethodLogAssert::reset();
        $reviews->publish($kingdom['record'], 'auto');
        $this->assertNotNull($transactions->findByTellerTransactionId('auto')?->getPublishedAt());
    }

    public function testPublishRejectsUncategorized(): void
    {
        $kingdom = $this->kingdomWithAccount();
        $reviews = Strategies::reviewService($transactions = $kingdom['transactions'], $kingdom['accounts'], Strategies::months(), new \DateTimeImmutable('2026-10-01'));
        $transactions->upsert(TransactionRecord::builder()
            ->kingdomId((int) $kingdom['record']->getId())
            ->tellerTransactionId('uncat')
            ->tellerAccountId('acc')
            ->postedOn('2026-09-02')
            ->amountCents(-100)
            ->category('uncategorized')
            ->publishableAfter('2026-09-01T00:00:00+00:00')
            ->build());
        MethodLogAssert::reset();
        try {
            $reviews->publish($kingdom['record'], 'uncat');
            $this->fail('Expected uncategorized rejection.');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Debug, 'transaction_review_rejected_uncategorized', TransactionReviewService::class . '::publish');
    }

    public function testHardRowPublishesDespiteUncategorizedSlug(): void
    {
        $kingdom = $this->kingdomWithAccount();
        $reviews = Strategies::reviewService($transactions = $kingdom['transactions'], $kingdom['accounts'], Strategies::months(), new \DateTimeImmutable('2026-10-01'));
        $flags = PublicationFlags::empty()->withHardPattern('ingest.test_hard')->encode();
        $transactions->upsert(TransactionRecord::builder()
            ->kingdomId((int) $kingdom['record']->getId())
            ->tellerTransactionId('hard')
            ->tellerAccountId('acc')
            ->postedOn('2026-09-02')
            ->amountCents(25)
            ->category('uncategorized')
            ->publicationFlags($flags)
            ->publishableAfter('2026-09-01T00:00:00+00:00')
            ->build());
        MethodLogAssert::reset();
        $reviews->publish($kingdom['record'], 'hard');
        $this->assertNotNull($transactions->findByTellerTransactionId('hard')?->getPublishedAt());
    }

    public function testUpdateSetsManagerCategoryAndInvalidatesMonth(): void
    {
        $kingdom = $this->kingdomWithAccount();
        $cache = new ArrayStore();
        $reviews = Strategies::reviewService($kingdom['transactions'], $kingdom['accounts'], Strategies::months($cache, null, $kingdom['transactions']), new \DateTimeImmutable('2026-10-01'));
        $kingdom['transactions']->upsert(TransactionRecord::builder()
            ->kingdomId((int) $kingdom['record']->getId())
            ->tellerTransactionId('edit')
            ->tellerAccountId('acc')
            ->postedOn('2026-09-02')
            ->amountCents(-100)
            ->category('uncategorized')
            ->build());
        $warmKey = sprintf(
            'denarius:month:%d:less_redacted:2026-09:%s',
            $kingdom['record']->getId(),
            \Amtgard\Denarius\Tests\Support\CategorizationArrange::bundledCatalog()->taxonomyVersion(),
        );
        $cache->setPersistent($warmKey, '{}');
        MethodLogAssert::reset();
        $reviews->update($kingdom['record'], 'edit', 'expense.feast_groceries', false, false);
        $stored = $kingdom['transactions']->findByTellerTransactionId('edit');
        $this->assertSame('expense.feast_groceries', $stored?->getCategory());
        $this->assertSame(CategorySource::Manager->value, $stored?->getCategorySource());
        $this->assertSame(100, $stored?->getCategoryConfidence());
        $this->assertNull($cache->get($warmKey));
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Info, 'transaction_review_category_set', TransactionReviewService::class . '::update');
    }

    public function testUpdateRejectsUnknownAndSystemSlugs(): void
    {
        $kingdom = $this->kingdomWithAccount();
        $reviews = Strategies::reviewService($kingdom['transactions'], $kingdom['accounts']);
        $this->seedReviewRow($kingdom, 'bad');
        MethodLogAssert::reset();
        try {
            $reviews->update($kingdom['record'], 'bad', 'not.a.real.slug', false, false);
            $this->fail('Expected unknown slug rejection.');
        } catch (\InvalidArgumentException) {
        }
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Debug, 'transaction_review_rejected_category', ReviewCategoryValidator::class . '::assertAssignable');
        MethodLogAssert::reset();
        try {
            $reviews->update($kingdom['record'], 'bad', 'system.bank_verification', false, false);
            $this->fail('Expected system slug rejection.');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testUpdateRejectsFlowMismatch(): void
    {
        $kingdom = $this->kingdomWithAccount();
        $reviews = Strategies::reviewService($kingdom['transactions'], $kingdom['accounts']);
        $kingdom['transactions']->upsert(TransactionRecord::builder()
            ->kingdomId((int) $kingdom['record']->getId())
            ->tellerTransactionId('credit')
            ->tellerAccountId('acc')
            ->postedOn('2026-09-02')
            ->amountCents(500)
            ->category('uncategorized')
            ->build());
        MethodLogAssert::reset();
        try {
            $reviews->update($kingdom['record'], 'credit', 'expense.feast_groceries', false, false);
            $this->fail('Expected flow mismatch.');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Debug, 'transaction_review_rejected_category', ReviewCategoryValidator::class . '::assertAssignable');
    }

    public function testBulkCounterpartyAppliesCategoryWithinMonth(): void
    {
        $kingdom = $this->kingdomWithAccount();
        $reviews = Strategies::reviewService($kingdom['transactions'], $kingdom['accounts']);
        foreach (['a', 'b', 'c'] as $id) {
            $kingdom['transactions']->upsert(TransactionRecord::builder()
                ->kingdomId((int) $kingdom['record']->getId())
                ->tellerTransactionId($id)
                ->tellerAccountId('acc')
                ->postedOn($id === 'c' ? '2026-08-02' : '2026-09-03')
                ->amountCents(-100)
                ->counterparty('Same Shop')
                ->category('uncategorized')
                ->build());
        }
        $reviews->update($kingdom['record'], 'a', 'expense.feast_groceries', false, true);
        $this->assertSame('expense.feast_groceries', $kingdom['transactions']->findByTellerTransactionId('a')?->getCategory());
        $this->assertSame('expense.feast_groceries', $kingdom['transactions']->findByTellerTransactionId('b')?->getCategory());
        $this->assertSame('uncategorized', $kingdom['transactions']->findByTellerTransactionId('c')?->getCategory());
    }

    public function testWithholdClearsPublishedAt(): void
    {
        class_exists(ApplicationTest::class);
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
            ->category('expense.feast_groceries')
            ->description('supplies')
            ->counterparty('Shop')
            ->status('posted')
            ->publishedAt('2026-09-04T00:00:00+00:00')
            ->build());

        $reviews = Strategies::reviewService($transactions, $accounts, Strategies::months(), $now);
        MethodLogAssert::reset();
        $reviews->withhold($kingdom, 't1');

        $this->assertNull($transactions->findByTellerTransactionId('t1')?->getPublishedAt());
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Info, 'transaction_review_withheld', TransactionReviewService::class . '::withhold');
    }

    public function testPublishRejectsEmbargoedRow(): void
    {
        class_exists(ApplicationTest::class);
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
            ->category('expense.feast_groceries')
            ->publishableAfter('2026-09-10T00:00:00+00:00')
            ->build());

        $reviews = Strategies::reviewService($transactions, $accounts, Strategies::months(), $now);
        MethodLogAssert::reset();
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
            ->category('uncategorized')
            ->publishableAfter('2026-09-05T00:00:00+00:00')
            ->build());
        $transactions->upsert(TransactionRecord::builder()
            ->kingdomId((int) $kingdom->getId())
            ->tellerTransactionId('embargo')
            ->tellerAccountId('acc')
            ->postedOn('2026-09-04')
            ->amountCents(-60)
            ->category('uncategorized')
            ->publishableAfter('2026-09-10T00:00:00+00:00')
            ->build());
        $transactions->upsert(TransactionRecord::builder()
            ->kingdomId((int) $kingdom->getId())
            ->tellerTransactionId('live')
            ->tellerAccountId('acc')
            ->postedOn('2026-08-20')
            ->amountCents(-70)
            ->category('expense.feast_groceries')
            ->publishedAt('2026-08-21T00:00:00+00:00')
            ->build());

        MethodLogAssert::reset();
        $rows = Strategies::reviewQueue($transactions, $accounts, $now)->rowsForManage($kingdom);
        $this->assertCount(3, $rows);
        $statuses = array_column($rows, 'status');
        $this->assertContains('pending', $statuses);
        $this->assertContains('embargoed', $statuses);
        $this->assertContains('published', $statuses);
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Debug, 'transaction_review_queue_loaded', TransactionReviewQueue::class . '::rowsForManage');
    }

    public function testUncategorizedFilterSortsLeastConfidentFirst(): void
    {
        $kingdom = $this->kingdomWithAccount();
        $transactions = $kingdom['transactions'];
        $transactions->upsert(TransactionRecord::builder()
            ->kingdomId((int) $kingdom['record']->getId())
            ->tellerTransactionId('low')
            ->tellerAccountId('acc')
            ->postedOn('2026-09-04')
            ->amountCents(-10)
            ->category('uncategorized')
            ->categoryConfidence(10)
            ->build());
        $transactions->upsert(TransactionRecord::builder()
            ->kingdomId((int) $kingdom['record']->getId())
            ->tellerTransactionId('high')
            ->tellerAccountId('acc')
            ->postedOn('2026-09-01')
            ->amountCents(-20)
            ->category('uncategorized')
            ->categoryConfidence(55)
            ->build());
        $transactions->upsert(TransactionRecord::builder()
            ->kingdomId((int) $kingdom['record']->getId())
            ->tellerTransactionId('done')
            ->tellerAccountId('acc')
            ->postedOn('2026-09-02')
            ->amountCents(-30)
            ->category('expense.feast_groceries')
            ->build());

        $rows = Strategies::reviewQueue($transactions, $kingdom['accounts'])->rowsForManage($kingdom['record'], true);
        $this->assertCount(2, $rows);
        $this->assertSame('low', $rows[0]['tellerTransactionId']);
    }

    public function testUpdateWithPublishRejectsUncategorized(): void
    {
        $kingdom = $this->kingdomWithAccount();
        $reviews = Strategies::reviewService($kingdom['transactions'], $kingdom['accounts']);
        $this->seedReviewRow($kingdom, 'row');
        MethodLogAssert::reset();
        try {
            $reviews->update($kingdom['record'], 'row', 'uncategorized', true, false);
            $this->fail('Expected publish rejection.');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Debug, 'transaction_review_rejected_uncategorized', TransactionReviewService::class . '::update');
    }

    /**
     * @return array{record: KingdomRecord, accounts: MemoryAccounts, transactions: MemoryTransactions}
     */
    private function kingdomWithAccount(): array
    {
        class_exists(ApplicationTest::class);
        $kingdoms = new MemoryKingdoms();
        $accounts = new MemoryAccounts();
        $transactions = new MemoryTransactions();
        $kingdom = $kingdoms->save(KingdomRecord::builder()->orkKingdomId(1)->name('Test')->slug('test')->build());
        $accounts->save(AccountRecord::builder()->kingdomId((int) $kingdom->getId())->tellerAccountId('acc')->name('Checking')->published(true)->build());

        return ['record' => $kingdom, 'accounts' => $accounts, 'transactions' => $transactions];
    }

    /**
     * @param array{record: KingdomRecord, accounts: MemoryAccounts, transactions: MemoryTransactions} $kingdom
     */
    private function seedReviewRow(array $kingdom, string $id): void
    {
        $kingdom['transactions']->upsert(TransactionRecord::builder()
            ->kingdomId((int) $kingdom['record']->getId())
            ->tellerTransactionId($id)
            ->tellerAccountId('acc')
            ->postedOn('2026-09-02')
            ->amountCents(-100)
            ->category('uncategorized')
            ->build());
    }
}