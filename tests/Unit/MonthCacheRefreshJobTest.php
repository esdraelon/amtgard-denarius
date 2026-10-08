<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Domain\Statement\MonthWindow;
use Amtgard\Denarius\Domain\Statement\Presentation\DisplayMode;
use Amtgard\Denarius\Persistence\Record\AccountRecord;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Service\Month\MonthCacheKeys;
use Amtgard\Denarius\Service\Kingdom\KingdomPageQuery;
use Amtgard\Denarius\Service\Month\MonthCacheRefreshPublisher;
use Amtgard\Denarius\Service\Month\MonthCacheWriter;
use Amtgard\Denarius\Tests\Support\CategorizationArrange;
use Amtgard\Denarius\Tests\Support\KingdomPageQueryFactory;
use Amtgard\Denarius\Tests\Support\MethodLogAssert;
use Amtgard\Denarius\Utilities\Log\BranchLogLevel;
use Amtgard\Denarius\Worker\Job\Impl\MonthCacheRefreshJob;
use Amtgard\PHPUnit\AmtgardTestCase;

final class MonthCacheRefreshJobTest extends AmtgardTestCase
{
    public function testPublisherSchedulesAndJobWritesRedisKeys(): void
    {
        class_exists(ApplicationTest::class);
        $kingdoms = new MemoryKingdoms();
        $accounts = new MemoryAccounts();
        $transactions = new MemoryTransactions();
        $store = new ArrayStore();
        $kingdom = $kingdoms->save(KingdomRecord::builder()->orkKingdomId(1)->name('Test')->slug('test')->displayMode('less_redacted')->build());
        $kingdomId = (int) $kingdom->getId();
        $accounts->save(AccountRecord::builder()->kingdomId($kingdomId)->tellerAccountId('acc')->name('Checking')->published(true)->build());
        $transactions->upsert(TransactionRecord::builder()
            ->kingdomId($kingdomId)
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

        $messages = new MemoryMessages();
        $publisher = new MonthCacheRefreshPublisher($messages, $transactions);
        $catalog = CategorizationArrange::bundledCatalog();
        $writer = new MonthCacheWriter($store, $catalog);
        $month = new MonthWindow(2026, 9);

        MethodLogAssert::reset();
        $publisher->schedule($kingdomId, $month);
        MethodLogAssert::assertBranchLogged(
            BranchLogLevel::Debug,
            'month_cache_refresh_scheduled',
            MonthCacheRefreshPublisher::class . '::schedule',
        );

        $this->assertCount(1, $messages->published);
        $payload = json_decode($messages->published[0]['message'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('month_cache', $payload['type']);
        $this->assertSame($kingdomId, $payload['kingdom_id']);
        $this->assertSame('2026-09', $payload['month']);

        $job = new MonthCacheRefreshJob(
            $kingdoms,
            KingdomPageQueryFactory::publicRead($transactions, $accounts),
            $writer,
        );
        MethodLogAssert::reset();
        $job->handle($payload);
        MethodLogAssert::assertBranchLogged(
            BranchLogLevel::Info,
            'month_cache_refresh_complete',
            MonthCacheRefreshJob::class . '::handle',
        );

        $keys = new MonthCacheKeys();
        $taxonomy = $catalog->taxonomyVersion();
        foreach (MonthCacheWriter::WARM_MODES as $mode) {
            $this->assertNotNull($store->get($keys->statement($kingdomId, $mode->value, '2026-09', $taxonomy)));
        }
        $this->assertSame(DisplayMode::LessRedacted->value, json_decode(
            (string) $store->get($keys->statement($kingdomId, DisplayMode::LessRedacted->value, '2026-09', $taxonomy)),
            true,
            flags: JSON_THROW_ON_ERROR,
        )['mode']);
    }

    public function testJobLogsFailureForInvalidPayloadAndMissingKingdom(): void
    {
        class_exists(ApplicationTest::class);
        $writer = new MonthCacheWriter(new ArrayStore(), CategorizationArrange::bundledCatalog());
        $job = new MonthCacheRefreshJob(
            new MemoryKingdoms(),
            KingdomPageQueryFactory::publicRead(new MemoryTransactions(), new MemoryAccounts()),
            $writer,
        );

        MethodLogAssert::reset();
        $job->handle(['kingdom_id' => 0, 'month' => '2026-09']);
        MethodLogAssert::assertBranchLogged(
            BranchLogLevel::Warn,
            'month_cache_refresh_failed',
            MonthCacheRefreshJob::class . '::handle',
        );

        MethodLogAssert::reset();
        $job->handle(['kingdom_id' => 99, 'month' => '2026-09']);
        MethodLogAssert::assertBranchLogged(
            BranchLogLevel::Warn,
            'month_cache_refresh_failed',
            MonthCacheRefreshJob::class . '::handle',
        );
        $this->assertSame(2, 2);
    }

    public function testJobRethrowsWhenWarmFails(): void
    {
        class_exists(ApplicationTest::class);
        $kingdoms = new MemoryKingdoms();
        $kingdom = $kingdoms->save(KingdomRecord::builder()->orkKingdomId(1)->name('Test')->slug('test')->build());
        $pages = $this->createMock(KingdomPageQuery::class);
        $pages->method('statementForMode')->willThrowException(new \RuntimeException('warm failed'));
        $job = new MonthCacheRefreshJob($kingdoms, $pages, new MonthCacheWriter(new ArrayStore(), CategorizationArrange::bundledCatalog()));

        MethodLogAssert::reset();
        try {
            $job->handle(['kingdom_id' => (int) $kingdom->getId(), 'month' => '2026-09']);
            $this->fail('Expected warm failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('warm failed', $exception->getMessage());
        }
        MethodLogAssert::assertBranchLogged(
            BranchLogLevel::Warn,
            'month_cache_refresh_failed',
            MonthCacheRefreshJob::class . '::handle',
        );
    }
}
