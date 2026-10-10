<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Tests\Support\CategoryCatalogFixture;
use Amtgard\Denarius\Domain\Statement\MonthWindow;
use Amtgard\Denarius\Domain\Statement\Publication\PublicationFlags;
use Amtgard\Denarius\Domain\Statement\Publication\PublicationSelection;
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
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('expense.feast_groceries'))
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
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('expense.site_rental'))
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
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('uncategorized'))
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
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('uncategorized'))
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
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('uncategorized'))
            ->build());
        $warmKey = sprintf(
            'denarius:month:%d:less_redacted:2026-09:%s',
            $kingdom['record']->getId(),
            \Amtgard\Denarius\Tests\Support\CategorizationArrange::bundledCatalog()->taxonomyVersion(),
        );
        $cache->setPersistent($warmKey, '{}');
        MethodLogAssert::reset();
        $reviews->update($kingdom['record'], 'edit', CategoryCatalogFixture::id('expense.feast_groceries'), false);
        $stored = $kingdom['transactions']->findByTellerTransactionId('edit');
        $this->assertSame(CategoryCatalogFixture::id('expense.feast_groceries'), $stored?->getCategoryId());
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
            $reviews->update($kingdom['record'], 'bad', 999_999, false);
            $this->fail('Expected unknown slug rejection.');
        } catch (\InvalidArgumentException) {
        }
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Debug, 'transaction_review_rejected_category', ReviewCategoryValidator::class . '::assertAssignable');
        MethodLogAssert::reset();
        try {
            $reviews->update($kingdom['record'], 'bad', CategoryCatalogFixture::id('system.bank_verification'), false);
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
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('uncategorized'))
            ->build());
        MethodLogAssert::reset();
        try {
            $reviews->update($kingdom['record'], 'credit', CategoryCatalogFixture::id('expense.feast_groceries'), false);
            $this->fail('Expected flow mismatch.');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Debug, 'transaction_review_rejected_category', ReviewCategoryValidator::class . '::assertAssignable');
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
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('expense.feast_groceries'))
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
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('expense.feast_groceries'))
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
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('uncategorized'))
            ->publishableAfter('2026-09-05T00:00:00+00:00')
            ->build());
        $transactions->upsert(TransactionRecord::builder()
            ->kingdomId((int) $kingdom->getId())
            ->tellerTransactionId('embargo')
            ->tellerAccountId('acc')
            ->postedOn('2026-09-04')
            ->amountCents(-60)
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('uncategorized'))
            ->publishableAfter('2026-09-10T00:00:00+00:00')
            ->build());
        $transactions->upsert(TransactionRecord::builder()
            ->kingdomId((int) $kingdom->getId())
            ->tellerTransactionId('live')
            ->tellerAccountId('acc')
            ->postedOn('2026-08-20')
            ->amountCents(-70)
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('expense.feast_groceries'))
            ->publishedAt('2026-08-21T00:00:00+00:00')
            ->build());

        MethodLogAssert::reset();
        $queue = Strategies::reviewQueue($transactions, $accounts, $now);
        $september = $queue->rowsForManage($kingdom, new MonthWindow(2026, 9));
        $this->assertSame(['embargo', 'pending'], array_column($september, 'tellerTransactionId'));
        $this->assertSame(['embargoed', 'needs_category'], array_column($september, 'status'));
        $august = $queue->rowsForManage($kingdom, new MonthWindow(2026, 8));
        $this->assertSame(['published'], array_column($august, 'status'));
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Debug, 'transaction_review_queue_loaded', TransactionReviewQueue::class . '::rowsForManage');
        $this->assertSame('2026-09', $queue->latestReviewMonth($kingdom)->key());
        $this->assertSame('2026-09', $queue->reviewMonth($kingdom, '')->key());
        $this->assertSame('2026-08', $queue->reviewMonth($kingdom, '2026-08')->key());
        $this->assertSame('2026-09', $queue->reviewMonth($kingdom, 'garbage')->key());
    }

    public function testLatestReviewMonthFallsBackToCurrentWhenQueueEmpty(): void
    {
        $kingdom = $this->kingdomWithAccount();
        $queue = Strategies::reviewQueue($kingdom['transactions'], $kingdom['accounts'], new \DateTimeImmutable('2026-11-15'));
        MethodLogAssert::reset();
        $this->assertSame('2026-11', $queue->latestReviewMonth($kingdom['record'])->key());
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Debug, 'transaction_review_month_current', TransactionReviewQueue::class . '::latestReviewMonth');
    }

    public function testCategorizedUnpublishedRowShowsReadyToPublishStatus(): void
    {
        $kingdom = $this->kingdomWithAccount();
        $transactions = $kingdom['transactions'];
        $transactions->upsert(TransactionRecord::builder()
            ->kingdomId((int) $kingdom['record']->getId())
            ->tellerTransactionId('ready')
            ->tellerAccountId('acc')
            ->postedOn('2026-10-02')
            ->amountCents(-100)
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('expense.feast_groceries'))
            ->categorySource(CategorySource::Manager->value)
            ->publishableAfter('2026-09-01T00:00:00+00:00')
            ->build());

        $rows = Strategies::reviewQueue($transactions, $kingdom['accounts'], new \DateTimeImmutable('2026-10-05'))
            ->rowsForManage($kingdom['record'], new MonthWindow(2026, 10));
        $byId = array_column($rows, null, 'tellerTransactionId');

        $this->assertSame('pending', $byId['ready']['status']);
        $this->assertStringContainsString('Feast', $byId['ready']['storedCategoryDisplay']);
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
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('uncategorized'))
            ->categoryConfidence(10)
            ->build());
        $transactions->upsert(TransactionRecord::builder()
            ->kingdomId((int) $kingdom['record']->getId())
            ->tellerTransactionId('high')
            ->tellerAccountId('acc')
            ->postedOn('2026-09-01')
            ->amountCents(-20)
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('uncategorized'))
            ->categoryConfidence(55)
            ->build());
        $transactions->upsert(TransactionRecord::builder()
            ->kingdomId((int) $kingdom['record']->getId())
            ->tellerTransactionId('done')
            ->tellerAccountId('acc')
            ->postedOn('2026-09-02')
            ->amountCents(-30)
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('expense.feast_groceries'))
            ->build());

        $rows = Strategies::reviewQueue($transactions, $kingdom['accounts'])->rowsForManage($kingdom['record'], new MonthWindow(2026, 9), true);
        $this->assertCount(2, $rows);
        $this->assertSame('low', $rows[0]['tellerTransactionId']);
    }

    public function testApplyPublicationSelectionsUpdatesFlagsPublishesAndInvalidatesTouchedMonths(): void
    {
        $kingdom = $this->kingdomWithAccount();
        $transactions = $kingdom['transactions'];
        $kingdomId = (int) $kingdom['record']->getId();
        $seed = static function (string $id, string $posted, string $category, string $after, ?string $publishedAt = null) use ($transactions, $kingdomId): void {
            $transactions->upsert(TransactionRecord::builder()
                ->kingdomId($kingdomId)
                ->tellerTransactionId($id)
                ->tellerAccountId('acc')
                ->postedOn($posted)
                ->amountCents(-100)
                ->categoryId(CategoryCatalogFixture::id($category))
                ->publishableAfter($after)
                ->publishedAt($publishedAt)
                ->build());
        };
        $seed('open', '2026-09-01', 'expense.feast_groceries', '2026-09-05T00:00:00+00:00');
        $seed('waive', '2026-09-20', 'expense.feast_groceries', '2026-09-24T23:59:59+00:00');
        $seed('hold', '2026-09-20', 'expense.feast_groceries', '2026-09-24T23:59:59+00:00');
        $seed('uncat', '2026-09-02', 'uncategorized', '2026-09-05T00:00:00+00:00');
        $seed('live', '2026-08-10', 'expense.feast_groceries', '2026-08-14T00:00:00+00:00', '2026-08-15T00:00:00+00:00');
        $cache = new ArrayStore();
        $version = \Amtgard\Denarius\Tests\Support\CategorizationArrange::bundledCatalog()->taxonomyVersion();
        $warm = static fn (string $month): string => sprintf('denarius:month:%d:less_redacted:%s:%s', $kingdomId, $month, $version);
        foreach (['2026-07', '2026-08', '2026-09'] as $month) {
            $cache->setPersistent($warm($month), '{}');
        }
        $reviews = Strategies::reviewService($transactions, $kingdom['accounts'], Strategies::months($cache, null, $transactions), new \DateTimeImmutable('2026-09-21T12:00:00+00:00'));
        MethodLogAssert::reset();

        $reviews->applyPublicationSelections(
            $kingdom['record'],
            new PublicationSelection('open', true, false, true),
            new PublicationSelection('waive', true, false, false),
            new PublicationSelection('hold', true, false, true),
            new PublicationSelection('uncat', false, true, true),
            new PublicationSelection('live', false, false, true),
        );

        $this->assertSame('2026-09-21T12:00:00+00:00', $transactions->findByTellerTransactionId('open')?->getPublishedAt());
        $waived = $transactions->findByTellerTransactionId('waive');
        $this->assertNotNull($waived?->getPublishedAt());
        $this->assertSame('2026-09-21T00:00:00+00:00', $waived?->getPublishableAfter());
        $this->assertTrue(PublicationFlags::parse($waived?->getPublicationFlags())->isManagerEmbargoWaived());
        $held = $transactions->findByTellerTransactionId('hold');
        $this->assertNull($held?->getPublishedAt());
        $this->assertFalse(PublicationFlags::parse($held?->getPublicationFlags())->isManagerEmbargoWaived());
        $uncat = $transactions->findByTellerTransactionId('uncat');
        $this->assertNull($uncat?->getPublishedAt());
        $this->assertTrue(PublicationFlags::parse($uncat?->getPublicationFlags())->isManagerRedactDescription());
        $this->assertNull($transactions->findByTellerTransactionId('live')?->getPublishedAt());
        $this->assertNull($cache->get($warm('2026-09')));
        $this->assertNull($cache->get($warm('2026-08')));
        $this->assertSame('{}', $cache->get($warm('2026-07')));

        $method = TransactionReviewService::class . '::applyPublicationSelections';
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Info, 'transaction_review_selections_applied', $method);
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Info, 'transaction_review_published', $method);
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Info, 'transaction_review_withheld', $method);
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Debug, 'transaction_review_skipped_embargo', $method);
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Debug, 'transaction_review_skipped_uncategorized', $method);

        $rows = Strategies::reviewQueue($transactions, $kingdom['accounts'], new \DateTimeImmutable('2026-09-21T12:00:00+00:00'))
            ->rowsForManage($kingdom['record'], new MonthWindow(2026, 9));
        $byId = array_column($rows, null, 'tellerTransactionId');
        $this->assertSame(['publish' => true, 'redact' => false, 'embargo' => false], array_intersect_key($byId['open'], ['publish' => 1, 'redact' => 1, 'embargo' => 1]));
        $this->assertSame(['publish' => false, 'redact' => true, 'embargo' => false], array_intersect_key($byId['uncat'], ['publish' => 1, 'redact' => 1, 'embargo' => 1]));
        $this->assertTrue($byId['hold']['embargo']);
    }

    public function testApplyPublicationSelectionsValidatesEveryRowBeforeWriting(): void
    {
        $kingdom = $this->kingdomWithAccount();
        $this->seedReviewRow($kingdom, 'valid');
        $cache = new ArrayStore();
        $warmKey = sprintf(
            'denarius:month:%d:less_redacted:2026-09:%s',
            $kingdom['record']->getId(),
            \Amtgard\Denarius\Tests\Support\CategorizationArrange::bundledCatalog()->taxonomyVersion(),
        );
        $cache->setPersistent($warmKey, '{}');
        $reviews = Strategies::reviewService($kingdom['transactions'], $kingdom['accounts'], Strategies::months($cache, null, $kingdom['transactions']));
        MethodLogAssert::reset();
        $reviews->applyPublicationSelections($kingdom['record']);
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Debug, 'transaction_review_selections_empty', TransactionReviewService::class . '::applyPublicationSelections');
        $this->assertSame('{}', $cache->get($warmKey));
        try {
            $reviews->applyPublicationSelections(
                $kingdom['record'],
                new PublicationSelection('valid', false, true, false),
                new PublicationSelection('missing', true, false, false),
            );
            $this->fail('Expected missing row rejection.');
        } catch (\InvalidArgumentException) {
            $this->assertNull($kingdom['transactions']->findByTellerTransactionId('valid')?->getPublicationFlags());
        }
    }

    public function testUpdateWithPublishRejectsUncategorized(): void
    {
        $kingdom = $this->kingdomWithAccount();
        $reviews = Strategies::reviewService($kingdom['transactions'], $kingdom['accounts']);
        $this->seedReviewRow($kingdom, 'row');
        MethodLogAssert::reset();
        try {
            $reviews->update($kingdom['record'], 'row', CategoryCatalogFixture::id('uncategorized'), true);
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
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('uncategorized'))
            ->build());
    }
}