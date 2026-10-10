<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Tests\Support\CategoryCatalogFixture;
use Amtgard\Denarius\Domain\Statement\MonthWindow;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\PublicationCandidateLine;
use Amtgard\Denarius\Domain\Statement\Publication\StatementAbsenceClassifier;
use Amtgard\Denarius\Domain\Statement\Publication\StatementAbsenceReason;
use Amtgard\Denarius\Persistence\Record\AccountRecord;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Service\Month\Impl\CachingMonthReader;
use Amtgard\Denarius\Tests\Support\KingdomPageQueryFactory;
use Amtgard\Denarius\Tests\Support\MethodLogAssert;
use Amtgard\Denarius\Tests\Unit\ApplicationTest;
use Amtgard\Denarius\Utilities\Log\BranchLogLevel;
use Amtgard\PHPUnit\AmtgardTestCase;

final class StatementAbsenceTest extends AmtgardTestCase
{
    public function testClassifierMarksUnreviewedWhenEmbargoClearedButUnpublished(): void
    {
        class_exists(ApplicationTest::class);
        $classifier = new StatementAbsenceClassifier();
        $month = new MonthWindow(2026, 9);
        $asOf = new \DateTimeImmutable('2026-10-01T12:00:00+00:00');
        $line = PublicationCandidateLine::builder()
            ->postedOn('2026-09-02')
            ->amountCents(-100)
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('uncategorized'))
            ->publishableAfter('2026-09-05T00:00:00+00:00')
            ->build();

        $reason = $classifier->classify($month, $asOf, [$line]);
        $this->assertSame('Unreviewed', $reason->message());
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Debug, 'statement_absence_unreviewed', StatementAbsenceClassifier::class . '::classify');
    }

    public function testClassifierMarksStaleWhenMonthActivityStillEmbargoed(): void
    {
        class_exists(ApplicationTest::class);
        $classifier = new StatementAbsenceClassifier();
        $month = new MonthWindow(2026, 9);
        $asOf = new \DateTimeImmutable('2026-09-06T12:00:00+00:00');
        $line = PublicationCandidateLine::builder()
            ->postedOn('2026-09-02')
            ->amountCents(-100)
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('uncategorized'))
            ->publishableAfter('2026-09-10T00:00:00+00:00')
            ->build();

        $reason = $classifier->classify($month, $asOf, [$line]);
        $this->assertSame('Stale transactions', $reason->message());
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Debug, 'statement_absence_stale', StatementAbsenceClassifier::class . '::classify');
    }

    public function testClassifierMarksNoCurrentSinceWhenMonthIsEmpty(): void
    {
        class_exists(ApplicationTest::class);
        $classifier = new StatementAbsenceClassifier();
        $month = new MonthWindow(2026, 9);
        $asOf = new \DateTimeImmutable('2026-10-01T12:00:00+00:00');
        $line = PublicationCandidateLine::builder()
            ->postedOn('2026-08-15')
            ->amountCents(-100)
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('uncategorized'))
            ->publishedAt('2026-08-16T00:00:00+00:00')
            ->build();

        $reason = $classifier->classify($month, $asOf, [$line]);
        $this->assertSame('No current transactions since 2026-08-15', $reason->message());
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Debug, 'statement_absence_no_since', StatementAbsenceClassifier::class . '::classify');
    }

    public function testAbsenceReasonSerializesForCache(): void
    {
        $reason = StatementAbsenceReason::noCurrentSince('2026-08-15');
        $this->assertSame(StatementAbsenceReason::KIND_NO_SINCE, $reason->kind());
        $this->assertSame('2026-08-15', $reason->sinceDate());
        $roundTrip = StatementAbsenceReason::fromCache($reason->toCache());
        $this->assertNotNull($roundTrip);
        $this->assertSame($reason->message(), $roundTrip->message());
        StatementAbsenceReason::unreviewed()->message();
        StatementAbsenceReason::staleTransactions()->message();
        $this->assertNull(StatementAbsenceReason::fromCache(['kind' => 'unknown']));
    }

    public function testAbsenceReasonRoundTripsThroughMonthCache(): void
    {
        $store = new ArrayStore();
        $month = new MonthWindow(2026, 9);
        $kingdom = KingdomRecord::builder()->id(4)->orkKingdomId(8)->name('Golden Plains')->slug('golden-plains')->displayMode('redacted')->build();
        $origin = new class implements \Amtgard\Denarius\Service\Month\MonthReader {
            public function statement(KingdomRecord $kingdom, MonthWindow $month): \Amtgard\Denarius\Domain\Statement\MonthStatement
            {
                return new \Amtgard\Denarius\Domain\Statement\MonthStatement(
                    \Amtgard\Denarius\Domain\Statement\Presentation\DisplayMode::Redacted,
                    $month,
                    [],
                    StatementAbsenceReason::unreviewed(),
                );
            }
        };
        $reader = new CachingMonthReader(
            $origin,
            $store,
            new \Amtgard\Denarius\Service\Month\MonthCacheWriter($store, \Amtgard\Denarius\Tests\Support\CategorizationArrange::bundledCatalog()),
        );
        $first = $reader->statement($kingdom, $month);
        $second = $reader->statement($kingdom, $month);
        $this->assertSame('Unreviewed', $first->absenceReason?->message());
        $this->assertSame('Unreviewed', $second->absenceReason?->message());
    }

    public function testPublicKingdomQueryAttachesAbsenceWhenRowsEmpty(): void
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
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('uncategorized'))
            ->description('supplies')
            ->counterparty('Shop')
            ->status('posted')
            ->publishableAfter('2026-09-01T00:00:00+00:00')
            ->build());

        $query = KingdomPageQueryFactory::publicRead($transactions, $accounts);
        $statement = $query->statement($kingdom, new MonthWindow(2026, 9));
        $this->assertSame([], $statement->rows);
        $this->assertSame('Unreviewed', $statement->absenceReason?->message());
    }
}
