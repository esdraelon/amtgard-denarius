<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Domain\Statement\Line\CategoryTotal;
use Amtgard\Denarius\Domain\Statement\Line\LedgerLine;
use Amtgard\Denarius\Domain\Statement\MonthStatement;
use Amtgard\Denarius\Domain\Statement\MonthWindow;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Tests\Support\CategorizationArrange;
use Amtgard\Denarius\Service\Month\Impl\CachingMonthReader;
use Amtgard\Denarius\Service\Month\MonthInvalidator;
use Amtgard\Denarius\Service\Month\MonthReader;
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
                    \Amtgard\Denarius\Domain\Statement\Presentation\DisplayMode::LessRedacted,
                    $month,
                    [LedgerLine::builder()->postedOn('2026-09-02')->amountCents(250)->category('office')->description('paper')->counterparty('Shop')->build()],
                );
            }
        };
        $catalog = CategorizationArrange::bundledCatalog();
        $reader = new CachingMonthReader($origin, $store, $catalog);
        $first = $reader->statement($kingdom, $month);
        $second = $reader->statement($kingdom, $month);
        $this->assertSame(1, $origin->calls);
        $this->assertSame(250, $second->rows[0]->getAmountCents());
        $this->assertSame('paper', $first->rows[0]->getDescription());

        (new MonthInvalidator($store))->forget(4);
        $reader->statement($kingdom, $month);
        $this->assertSame(2, $origin->calls);

        $store->set('denarius:month:4:1:less_redacted:2026-09:taxonomy/v1', 'not-json', 10);
        $reader->statement($kingdom, $month);
        $this->assertSame(3, $origin->calls);

        $summary = new class implements MonthReader {
            public function statement(KingdomRecord $kingdom, MonthWindow $month): MonthStatement
            {
                return new MonthStatement(
                    \Amtgard\Denarius\Domain\Statement\Presentation\DisplayMode::Summarized,
                    $month,
                    [new CategoryTotal('office', 2, 250)],
                );
            }
        };
        $summarizedKingdom = KingdomRecord::builder()->id(5)->orkKingdomId(8)->name('Golden Plains')->slug('golden-plains')->displayMode('summarized')->build();
        $cached = new CachingMonthReader($summary, $store, $catalog);
        $cached->statement($summarizedKingdom, $month);
        $again = $cached->statement($summarizedKingdom, $month);
        $this->assertSame(2, $again->rows[0]->count);
        $this->assertSame(250, $again->rows[0]->amountCents);
    }
}
