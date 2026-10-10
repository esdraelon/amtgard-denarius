<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Tests\Support\CategoryCatalogFixture;
use Amtgard\Denarius\Domain\Statement\MonthWindow;
use Amtgard\Denarius\Domain\Statement\Presentation\DisplayMode;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\EmbargoStage;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\ManagerDescriptionRedactStage;
use Amtgard\Denarius\Domain\Statement\Publication\PublicationFlags;
use Amtgard\Denarius\Domain\Statement\Publication\PublicationManagerRedactCopy;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\PublicationCandidateLine;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\PublicationEnvelope;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\PublicationPipelineFactory;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\PublicationStatusStage;
use Amtgard\Denarius\Persistence\Record\AccountRecord;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Tests\Support\KingdomPageQueryFactory;
use Amtgard\Denarius\Tests\Support\TaxonomyCatalogFixture;
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
    }

    public function testEmbargoStageDropsLinesBeforePublishableAfter(): void
    {
        MethodLogAssert::reset();
        $kingdom = KingdomRecord::builder()->orkKingdomId(1)->name('K')->slug('k')->build();
        $month = new MonthWindow(2026, 9);
        $line = PublicationCandidateLine::builder()
            ->postedOn('2026-09-01')
            ->amountCents(-100)
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('uncategorized'))
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
        MethodLogAssert::reset();
        $kingdom = KingdomRecord::builder()->orkKingdomId(1)->name('K')->slug('k')->build();
        $month = new MonthWindow(2026, 9);
        $line = PublicationCandidateLine::builder()->postedOn('2026-09-01')->amountCents(-100)->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('uncategorized'))->build();
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
        $kingdom = $kingdoms->save(KingdomRecord::builder()->orkKingdomId(1)->name('Test')->slug('test')->displayMode('redacted')->build());
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
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('uncategorized'))
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

        $result = PublicationPipelineFactory::standard(
            TaxonomyCatalogFixture::load(),
            CategoryCatalogFixture::asInterface(),
        )->forPublicRead()->run($envelope);
        $result->kingdom();
        $result->month();
        $result->disclosureTier();
        $result->asOf();
        $result->lines();
        $this->assertCount(1, $result->toLedgerLines());
        PublicationPipelineFactory::standard()->forManagerReview()->run($envelope);
    }

    public function testManagerDescriptionRedactStageClearsDescription(): void
    {
        MethodLogAssert::reset();
        $flagged = $this->candidate(PublicationFlags::empty()->withManagerRedactDescription(true)->encode());
        $plain = $this->candidate(null);

        $result = (new ManagerDescriptionRedactStage())->process($this->envelope(DisplayMode::LessRedacted, [$flagged, $plain]));

        $redacted = $result->lines()[0];
        $this->assertSame(PublicationManagerRedactCopy::LINE_DESCRIPTION, $redacted->getDescription());
        $this->assertSame('', $redacted->getCounterparty());
        $this->assertSame('expense', $redacted->getCategoryFlow());
        $this->assertSame(-100, $redacted->getAmountCents());
        $this->assertSame('t-1', $redacted->getTellerTransactionId());
        $this->assertSame($flagged->getPublicationFlags(), $redacted->getPublicationFlags());
        $this->assertSame($plain, $result->lines()[1]);
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Debug, 'publication_manager_description_redact', ManagerDescriptionRedactStage::class . '::process');
    }

    public function testPublicPipelineAppliesManagerDescriptionRedact(): void
    {
        $flagged = $this->candidate(PublicationFlags::empty()->withManagerRedactDescription(true)->encode());

        $lines = PublicationPipelineFactory::standard(
            TaxonomyCatalogFixture::load(),
            CategoryCatalogFixture::asInterface(),
        )
            ->forPublicRead()
            ->run($this->envelope(DisplayMode::LessRedacted, [$flagged, $this->candidate(null)]))
            ->toLedgerLines();

        $this->assertCount(2, $lines);
        $this->assertSame(PublicationManagerRedactCopy::LINE_DESCRIPTION, $lines[0]->getDescription());
        $this->assertSame('private', $lines[1]->getDescription());
    }

    private function candidate(?string $flags): PublicationCandidateLine
    {
        return PublicationCandidateLine::builder()
            ->tellerTransactionId('t-1')
            ->postedOn('2026-09-01')
            ->amountCents(-100)
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('general'))
            ->categoryFlow('expense')
            ->description('private')
            ->counterparty('Vendor')
            ->accountName('Checking')
            ->publishedAt('2026-09-03T00:00:00+00:00')
            ->publishableAfter('2026-09-01T00:00:00+00:00')
            ->publicationFlags($flags)
            ->build();
    }

    /**
     * @param list<PublicationCandidateLine> $lines
     */
    private function envelope(DisplayMode $tier, array $lines): PublicationEnvelope
    {
        return new PublicationEnvelope(
            KingdomRecord::builder()->orkKingdomId(1)->name('K')->slug('k')->build(),
            new MonthWindow(2026, 9),
            $tier,
            new \DateTimeImmutable('2026-10-01T12:00:00+00:00'),
            $lines,
        );
    }
}
