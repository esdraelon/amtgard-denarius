<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Tests\Support\CategoryCatalogFixture;
use Amtgard\Denarius\Domain\Statement\MonthWindow;
use Amtgard\Denarius\Domain\Statement\Presentation\DisplayMode;
use Amtgard\Denarius\Domain\Statement\Publication\Ingest\MicroDepositPairReconciler;
use Amtgard\Denarius\Domain\Statement\Publication\Ingest\PublicationHardPatternIds;
use Amtgard\Denarius\Domain\Statement\Publication\PublicationFlags;
use Amtgard\Denarius\Domain\Statement\Publication\PublicationHardRedactCopy;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\HardRedactionStage;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\PublicationCandidateLine;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\PublicationEnvelope;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Tests\Support\MethodLogAssert;
use Amtgard\Denarius\Tests\Support\RecordingMethodLog;
use Amtgard\Denarius\Tests\Unit\Strategies;
use Amtgard\Denarius\Utilities\Log\BranchLogLevel;
use Amtgard\PHPUnit\AmtgardTestCase;

final class PublicationHardRedactTest extends AmtgardTestCase
{
    private RecordingMethodLog $recorder;

    protected function setUp(): void
    {
        class_exists(ApplicationTest::class);
        $active = \Amtgard\Denarius\Tests\Support\MethodLogRecorder::active();
        $this->assertInstanceOf(RecordingMethodLog::class, $active);
        $this->recorder = $active;
    }

    public function testVerifyBrandKeywordMarksHard(): void
    {
        $transactions = new MemoryTransactions();
        $kingdom = KingdomRecord::builder()->id(1)->orkKingdomId(1)->name('K')->slug('k')->build();
        $incoming = TransactionRecord::builder()
            ->kingdomId(1)
            ->tellerTransactionId('t-brand')
            ->tellerAccountId('acc')
            ->postedOn('2026-09-01')
            ->amountCents(-500)
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('uncategorized'))
            ->description('Bank VERIFY deposit')
            ->counterparty('Stripe')
            ->status('posted')
            ->build();

        $applied = Strategies::publicationApplier($transactions)->apply($kingdom, $incoming, false);
        $this->assertTrue(PublicationFlags::parse($applied->getPublicationFlags())->isHard());
    }

    public function testKeywordIngestMarksHardFlags(): void
    {
        MethodLogAssert::reset();
        $transactions = new MemoryTransactions();
        $kingdom = KingdomRecord::builder()->id(1)->orkKingdomId(1)->name('K')->slug('k')->build();
        $incoming = TransactionRecord::builder()
            ->kingdomId(1)
            ->tellerTransactionId('t1')
            ->tellerAccountId('acc')
            ->postedOn('2026-09-01')
            ->amountCents(-500)
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('uncategorized'))
            ->description('ACCTVERIFY #ABC')
            ->counterparty('Bank')
            ->status('posted')
            ->build();

        $applied = Strategies::publicationApplier($transactions)->apply($kingdom, $incoming, false);
        $flags = PublicationFlags::parse($applied->getPublicationFlags());
        $this->assertTrue($flags->isHard());
        $this->assertContains(PublicationHardPatternIds::VERIFY_KEYWORD, $flags->patternIds());
        MethodLogAssert::assertBranchLogged(
            BranchLogLevel::Debug,
            'publication_hard_keyword',
            \Amtgard\Denarius\Domain\Statement\Publication\Ingest\VerificationKeywordHardMatcher::class . '::matches',
        );
    }

    public function testMicroDepositPairReconcilerMarksCluster(): void
    {
        MethodLogAssert::reset();
        $transactions = new MemoryTransactions();
        $kingdom = KingdomRecord::builder()->id(1)->orkKingdomId(1)->name('K')->slug('k')->build();
        $transactions->upsert(TransactionRecord::builder()
            ->kingdomId(1)
            ->tellerTransactionId('m1')
            ->tellerAccountId('acc')
            ->postedOn('2026-09-01')
            ->amountCents(32)
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('uncategorized'))
            ->description('credit')
            ->counterparty('')
            ->status('posted')
            ->build());
        $transactions->upsert(TransactionRecord::builder()
            ->kingdomId(1)
            ->tellerTransactionId('m2')
            ->tellerAccountId('acc')
            ->postedOn('2026-09-02')
            ->amountCents(45)
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('uncategorized'))
            ->description('credit')
            ->counterparty('')
            ->status('posted')
            ->build());

        (new MicroDepositPairReconciler($transactions))->reconcileAccount($kingdom, 'acc');

        $first = $transactions->findByTellerTransactionId('m1');
        $this->assertNotNull($first);
        $this->assertTrue(PublicationFlags::parse($first->getPublicationFlags())->isHard());
        MethodLogAssert::assertBranchLogged(
            BranchLogLevel::Debug,
            'publication_micro_pair_mark',
            MicroDepositPairReconciler::class . '::reconcileAccount',
        );
    }

    public function testPublicationFlagsRoundTrip(): void
    {
        $empty = PublicationFlags::empty();
        $this->assertNull($empty->encode());
        $merged = PublicationFlags::parse(null)->merge($empty->withHardPattern(PublicationHardPatternIds::VERIFY_KEYWORD));
        $this->assertTrue($merged->isHard());
        $this->assertSame(
            $merged->encode(),
            PublicationFlags::parse($merged->encode())->encode(),
        );
        $this->assertFalse(PublicationFlags::parse('{invalid')->isHard());
    }

    public function testPublicationFlagsCarryManagerRedactAndEmbargoWaiver(): void
    {
        $manager = PublicationFlags::empty()
            ->withManagerRedactDescription(true)
            ->withManagerEmbargoWaived(true);
        $this->assertNotNull($manager->encode());
        $parsed = PublicationFlags::parse($manager->encode());
        $this->assertTrue($parsed->isManagerRedactDescription());
        $this->assertTrue($parsed->isManagerEmbargoWaived());
        $this->assertFalse($parsed->isHard());

        $hardened = $parsed->withHardPattern(PublicationHardPatternIds::VERIFY_KEYWORD);
        $this->assertTrue($hardened->isManagerRedactDescription());
        $this->assertTrue($hardened->isManagerEmbargoWaived());

        $merged = PublicationFlags::empty()->withManagerEmbargoWaived(true)
            ->merge(PublicationFlags::empty()->withManagerRedactDescription(true));
        $this->assertTrue($merged->isManagerRedactDescription());
        $this->assertTrue($merged->isManagerEmbargoWaived());

        $this->assertNull($parsed->withManagerRedactDescription(false)->withManagerEmbargoWaived(false)->encode());
        $this->assertNotNull(PublicationFlags::empty()->withManagerRedactDescription(true)->encode());
        $this->assertNotNull(PublicationFlags::empty()->withManagerEmbargoWaived(true)->encode());
        $this->assertFalse(PublicationFlags::empty()->isManagerRedactDescription());
        $this->assertFalse(PublicationFlags::empty()->isManagerEmbargoWaived());
    }

    public function testApplierWaivesEmbargoWhenManagerFlagged(): void
    {
        MethodLogAssert::reset();
        $transactions = new MemoryTransactions();
        $kingdom = KingdomRecord::builder()->id(1)->orkKingdomId(1)->name('K')->slug('k')->embargoDays(10)->build();
        $incoming = TransactionRecord::builder()
            ->kingdomId(1)
            ->tellerTransactionId('t-waive')
            ->tellerAccountId('acc')
            ->postedOn('2026-09-01')
            ->amountCents(-500)
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('uncategorized'))
            ->description('supplies')
            ->counterparty('Shop')
            ->status('posted')
            ->build();
        $transactions->upsert(\Amtgard\Denarius\Domain\Statement\Publication\TransactionRecordRebuilder::from($incoming)
            ->publicationFlags(PublicationFlags::empty()->withManagerEmbargoWaived(true)->encode())
            ->build());
        $now = new \DateTimeImmutable('2026-09-03T15:30:00+00:00');

        $applied = Strategies::publicationApplier($transactions, $now)->apply($kingdom, $incoming, false);

        $this->assertSame('2026-09-03T00:00:00+00:00', $applied->getPublishableAfter());
        $this->assertTrue(PublicationFlags::parse($applied->getPublicationFlags())->isManagerEmbargoWaived());
        MethodLogAssert::assertBranchLogged(
            BranchLogLevel::Debug,
            'publication_embargo_waived',
            \Amtgard\Denarius\Service\Ledger\TransactionPublicationApplier::class . '::apply',
        );

        $unwaived = Strategies::publicationApplier(new MemoryTransactions(), $now)->apply($kingdom, $incoming, false);
        $this->assertNotSame('2026-09-03T00:00:00+00:00', $unwaived->getPublishableAfter());
    }

    public function testHardRedactionStageStubsLine(): void
    {
        MethodLogAssert::reset();
        $kingdom = KingdomRecord::builder()->orkKingdomId(1)->name('K')->slug('k')->build();
        $flags = PublicationFlags::empty()->withHardPattern(PublicationHardPatternIds::VERIFY_KEYWORD)->encode();
        $line = PublicationCandidateLine::builder()
            ->postedOn('2026-09-01')
            ->amountCents(32)
            ->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('uncategorized'))
            ->description('secret')
            ->counterparty('Plaid')
            ->publicationFlags($flags)
            ->build();
        $envelope = new PublicationEnvelope(
            $kingdom,
            new MonthWindow(2026, 9),
            DisplayMode::Redacted,
            new \DateTimeImmutable('2026-10-01'),
            [$line],
        );

        $result = (new HardRedactionStage())->process($envelope);
        $rows = $result->toLedgerLines();
        $this->assertCount(1, $rows);
        $this->assertSame(0, $rows[0]->getAmountCents());
        $this->assertSame(PublicationHardRedactCopy::LINE_DESCRIPTION, $rows[0]->getDescription());
        $this->assertSame('', $rows[0]->getCounterparty());
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Debug, 'publication_hard_redact', HardRedactionStage::class . '::process');
    }
}
