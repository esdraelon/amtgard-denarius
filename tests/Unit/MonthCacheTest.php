<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Tests\Support\CategoryCatalogFixture;
use Amtgard\Denarius\Domain\Access\Visibility;
use Amtgard\Denarius\Domain\Statement\Line\CategoryTotal;
use Amtgard\Denarius\Domain\Statement\Line\LedgerLine;
use Amtgard\Denarius\Domain\Statement\MonthStatement;
use Amtgard\Denarius\Domain\Statement\MonthWindow;
use Amtgard\Denarius\Domain\Statement\Presentation\DisplayMode;
use Amtgard\Denarius\Domain\Taxonomy\TransactionFlow;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Tests\Support\CategorizationArrange;
use Amtgard\Denarius\Tests\Support\MethodLogAssert;
use Amtgard\Denarius\Utilities\Log\BranchLogLevel;
use Amtgard\Denarius\Service\Month\Impl\CachingMonthReader;
use Amtgard\Denarius\Service\Month\MonthCacheRefreshPublisher;
use Amtgard\Denarius\Service\Month\MonthCacheWriter;
use Amtgard\Denarius\Service\Month\MonthReader;
use Amtgard\Denarius\Utilities\Queue\KeyValue\KeyValueStore;
use Amtgard\PHPUnit\AmtgardTestCase;

final class MonthCacheTest extends AmtgardTestCase
{
    public function testRedisMonthCacheServesAndForgets(): void
    {
        $store = new ArrayStore();
        $month = new MonthWindow(2026, 9);
        $kingdom = KingdomRecord::builder()->id(4)->orkKingdomId(8)->name('Golden Plains')->slug('golden-plains')->displayMode('all')->build();
        $origin = new class implements MonthReader {
            public int $calls = 0;
            public function statement(KingdomRecord $kingdom, MonthWindow $month): MonthStatement
            {
                $this->calls++;
                return new MonthStatement(
                    DisplayMode::LessRedacted,
                    $month,
                    [LedgerLine::builder()->postedOn('2026-09-02')->amountCents(250)->category('Office')->categoryFlow('expense')->description('paper')->counterparty('Shop')->build()],
                );
            }
        };
        $catalog = CategorizationArrange::bundledCatalog();
        $reader = new CachingMonthReader($origin, $store, new MonthCacheWriter($store, $catalog));
        $first = $reader->statement($kingdom, $month);
        $second = $reader->statement($kingdom, $month);
        $this->assertSame(1, $origin->calls);
        $this->assertSame(250, $second->rows[0]->getAmountCents());
        $this->assertSame('expense', $second->rows[0]->getCategoryFlow());
        $this->assertSame('paper', $first->rows[0]->getDescription());

        Strategies::months($store)->invalidate(4, $month);
        $reader->statement($kingdom, $month);
        $this->assertSame(2, $origin->calls);

        $store->set('denarius:month:4:less_redacted:2026-09:taxonomy/v1', 'not-json', 10);
        $reader->statement($kingdom, $month);
        $this->assertSame(3, $origin->calls);

        $summary = new class implements MonthReader {
            public function statement(KingdomRecord $kingdom, MonthWindow $month): MonthStatement
            {
                return new MonthStatement(
                    DisplayMode::Summarized,
                    $month,
                    [new CategoryTotal('office', 2, 250, TransactionFlow::Expense), new CategoryTotal('net', 2, -250, null, true)],
                );
            }
        };
        $summarizedKingdom = KingdomRecord::builder()->id(5)->orkKingdomId(8)->name('Golden Plains')->slug('golden-plains')->displayMode('summarized')->build();
        $cached = new CachingMonthReader($summary, $store, new MonthCacheWriter($store, $catalog));
        $cached->statement($summarizedKingdom, $month);
        $again = $cached->statement($summarizedKingdom, $month);
        $this->assertSame(2, $again->rows[0]->count);
        $this->assertSame(250, $again->rows[0]->amountCents);
        $this->assertSame(TransactionFlow::Expense, $again->rows[0]->flowSection);
        $this->assertFalse($again->rows[0]->isNetTotal);
        $this->assertNull($again->rows[1]->flowSection);
        $this->assertTrue($again->rows[1]->isNetTotal);
    }

    public function testMissWritesPersistentTaxonomyKeyedEntryWithoutTtl(): void
    {
        $store = new RecordingStore();
        $month = new MonthWindow(2026, 9);
        $kingdom = KingdomRecord::builder()->id(7)->orkKingdomId(8)->name('Golden Plains')->slug('golden-plains')->displayMode('summarized')->build();
        $origin = new class implements MonthReader {
            public function statement(KingdomRecord $kingdom, MonthWindow $month): MonthStatement
            {
                return new MonthStatement(DisplayMode::Summarized, $month, [new CategoryTotal('office', 1, 100)]);
            }
        };
        $catalog = CategorizationArrange::bundledCatalog();
        $reader = new CachingMonthReader($origin, $store, new MonthCacheWriter($store, $catalog));

        $reader->statement($kingdom, $month);
        $reader->statement($kingdom, $month);

        $expectedKey = sprintf('denarius:month:7:summarized:2026-09:%s', $catalog->taxonomyVersion());
        $this->assertSame([$expectedKey], $store->persistentWrites);
        $this->assertSame([], $store->ttlWrites);
    }

    public function testKingdomSettingsSaveDropsWarmMonthsAndSchedulesRebuild(): void
    {
        $store = new ArrayStore();
        $kingdoms = new MemoryKingdoms();
        $transactions = new MemoryTransactions();
        $messages = new MemoryMessages();
        $kingdom = $kingdoms->save(KingdomRecord::builder()->orkKingdomId(8)->name('Golden Plains')->slug('golden-plains')->displayMode('less_redacted')->build());
        $kingdomId = (int) $kingdom->getId();
        $transactions->upsert(TransactionRecord::builder()
            ->kingdomId($kingdomId)
            ->tellerTransactionId('t1')
            ->tellerAccountId('acc')
            ->postedOn('2026-09-02')
            ->amountCents(-100)
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('uncategorized'))
            ->build());
        $warmKey = sprintf('denarius:month:%d:summarized:2026-09:%s', $kingdomId, CategorizationArrange::bundledCatalog()->taxonomyVersion());
        $store->setPersistent($warmKey, '{}');
        $months = Strategies::months($store, new MonthCacheRefreshPublisher($messages, $transactions));

        MethodLogAssert::reset();
        Strategies::kingdomSettings($kingdoms, $months)->update($kingdom, Visibility::Public, DisplayMode::Summarized, 3);

        $this->assertNull($store->get($warmKey));
        $this->assertCount(1, $messages->published);
        $this->assertSame($kingdomId . ':2026-09', $messages->published[0]['key']);
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Debug, 'month_cache_refresh_scheduled', MonthCacheRefreshPublisher::class . '::schedule');
    }
}

final class RecordingStore implements KeyValueStore
{
    /** @var array<string, string> */
    public array $data = [];
    /** @var list<string> */
    public array $persistentWrites = [];
    /** @var list<string> */
    public array $ttlWrites = [];

    public function get(string $key): ?string
    {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, string $value, int $ttlSeconds): void
    {
        $this->ttlWrites[] = $key;
        $this->data[$key] = $value;
    }

    public function setPersistent(string $key, string $value): void
    {
        $this->persistentWrites[] = $key;
        $this->data[$key] = $value;
    }

    public function delete(string $key): void
    {
        unset($this->data[$key]);
    }
}
