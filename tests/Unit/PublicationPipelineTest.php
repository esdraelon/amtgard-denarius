<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Domain\Statement\MonthWindow;
use Amtgard\Denarius\Domain\Statement\Presentation\DisplayMode;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\EmbargoStage;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\PublicationCandidateLine;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\PublicationEnvelope;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\PublicationPipelineFactory;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\PublicationStatusStage;
use Amtgard\Denarius\Persistence\Record\AccountRecord;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Tests\Support\KingdomPageQueryFactory;
use Amtgard\Denarius\Tests\Support\MethodLogAssert;
use Amtgard\Denarius\Tests\Support\RecordingMethodLog;
use Amtgard\Denarius\Utilities\Log\BranchLogLevel;
use Amtgard\PHPUnit\AmtgardTestCase;

final class PublicationPipelineTest extends AmtgardTestCase
{
    private RecordingMethodLog $recorder;

    protected function setUp(): void
    {
        class_exists(ApplicationTest::class);
        $active = \Amtgard\Denarius\Tests\Support\MethodLogRecorder::active();
        $this->assertInstanceOf(RecordingMethodLog::class, $active);
        $this->recorder = $active;
        MethodLogAssert::reset();
    }

    public function testEmbargoStageDropsLinesBeforePublishableAfter(): void
    {
        $kingdom = KingdomRecord::builder()->orkKingdomId(1)->name('K')->slug('k')->build();
        $month = new MonthWindow(2026, 9);
        $line = PublicationCandidateLine::builder()
            ->postedOn('2026-09-01')
            ->amountCents(-100)
            ->category('general')
            ->publishedAt('2026-09-02T00:00:00+00:00')
            ->publishableAfter('2026-09-10T00:00:00+00:00')
            ->build();
        $envelope = new PublicationEnvelope(
            $kingdom,
            $month,
            DisplayMode::Redacted,
            new \DateTimeImmutable('2026-09-05T12:00:00+00:00'),
            [$line],
        );

        $result = (new EmbargoStage())->process($envelope);
        $this->assertCount(0, $result->lines());
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Debug, 'publication_embargo_hold', EmbargoStage::class . '::process');
    }

    public function testPublicationStatusStageDropsUnpublishedLines(): void
    {
        $kingdom = KingdomRecord::builder()->orkKingdomId(1)->name('K')->slug('k')->build();
        $month = new MonthWindow(2026, 9);
        $line = PublicationCandidateLine::builder()->postedOn('2026-09-01')->amountCents(-100)->category('general')->build();
        $envelope = new PublicationEnvelope(
            $kingdom,
            $month,
            DisplayMode::Redacted,
            new \DateTimeImmutable('2026-10-01T12:00:00+00:00'),
            [$line],
        );

        $result = (new PublicationStatusStage())->process($envelope);
        $this->assertCount(0, $result->lines());
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Debug, 'publication_unpublished', PublicationStatusStage::class . '::process');
    }

    public function testManagerQueryIncludesUnpublishedLines(): void
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

        $manager = KingdomPageQueryFactory::managerReview($transactions, $accounts);
        $public = KingdomPageQueryFactory::publicRead($transactions, $accounts);
        $month = new MonthWindow(2026, 9);

        $this->assertCount(1, $manager->statement($kingdom, $month)->rows);
        $this->assertCount(0, $public->statement($kingdom, $month)->rows);
    }

    public function testPublicPipelineRunsFullStageChain(): void
    {
        $kingdom = KingdomRecord::builder()->orkKingdomId(1)->name('K')->slug('k')->build();
        $month = new MonthWindow(2026, 9);
        $line = PublicationCandidateLine::builder()
            ->postedOn('2026-09-02')
            ->amountCents(-500)
            ->category('general')
            ->accountName('Checking')
            ->publishedAt('2026-09-03T00:00:00+00:00')
            ->publishableAfter('2026-09-01T00:00:00+00:00')
            ->build();
        $envelope = new PublicationEnvelope(
            $kingdom,
            $month,
            DisplayMode::Redacted,
            new \DateTimeImmutable('2026-10-01T12:00:00+00:00'),
            [$line],
        );

        $result = PublicationPipelineFactory::standard()->forPublicRead()->run($envelope);
        $result->kingdom();
        $result->month();
        $result->disclosureTier();
        $result->asOf();
        $result->lines();
        $this->assertCount(1, $result->toLedgerLines());
        PublicationPipelineFactory::standard()->forManagerReview()->run($envelope);
    }
}
