<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Domain\Statement\Publication\PublicationEmbargoCalculator;
use Amtgard\Denarius\Domain\Statement\Publication\PublicationSettingsValidator;
use Amtgard\Denarius\Persistence\Record\AccountRecord;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Service\Ledger\TransactionSynchronizer;
use Amtgard\Denarius\Tests\Unit\ApplicationTest;
use Amtgard\Denarius\Tests\Support\MethodLogAssert;
use Amtgard\Denarius\Utilities\Security\TokenCipher;
use Amtgard\Denarius\Tests\Support\RecordingMethodLog;
use Amtgard\Denarius\Utilities\Log\BranchLogLevel;
use Amtgard\PHPUnit\AmtgardTestCase;

final class PublicationEmbargoCalculatorTest extends AmtgardTestCase
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

    public function testStandardEmbargoEndsAfterPostedDayPlusEmbargoDays(): void
    {
        $calculator = new PublicationEmbargoCalculator();
        $now = new \DateTimeImmutable('2026-09-10T15:00:00+00:00');
        $after = $calculator->publishableAfter('2026-09-01', 3, $now, false);
        $this->assertSame('2026-09-04T23:59:59+00:00', $after);
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Debug, 'embargo_standard', PublicationEmbargoCalculator::class . '::publishableAfter');
    }

    public function testBackfillAmnestyMakesOldRowsEligibleAtStartOfToday(): void
    {
        $calculator = new PublicationEmbargoCalculator();
        $now = new \DateTimeImmutable('2026-09-10T12:00:00+00:00');
        $after = $calculator->publishableAfter('2026-09-01', 3, $now, true);
        $this->assertSame('2026-09-10T00:00:00+00:00', $after);
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Debug, 'embargo_backfill_amnesty', PublicationEmbargoCalculator::class . '::publishableAfter');
    }

    public function testBackfillAmnestyDoesNotApplyToRecentTail(): void
    {
        $calculator = new PublicationEmbargoCalculator();
        $now = new \DateTimeImmutable('2026-09-10T12:00:00+00:00');
        $after = $calculator->publishableAfter('2026-09-09', 3, $now, true);
        $this->assertSame('2026-09-12T23:59:59+00:00', $after);
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Debug, 'embargo_standard', PublicationEmbargoCalculator::class . '::publishableAfter');
    }

    public function testEmbargoDaysClampLogsWhenOutOfRange(): void
    {
        $validator = new PublicationSettingsValidator();
        MethodLogAssert::reset();
        $this->assertSame(7, $validator->clampEmbargoDays(99));
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Debug, 'embargo_days_clamped', PublicationSettingsValidator::class . '::clampEmbargoDays');
    }

    public function testFirstSyncLogsBackfillBranches(): void
    {
        MethodLogAssert::reset();
        $kingdoms = new MemoryKingdoms();
        $secrets = new MemorySecrets();
        $accounts = new MemoryAccounts();
        $transactions = new MemoryTransactions();
        $kingdom = $kingdoms->save(KingdomRecord::builder()
            ->orkKingdomId(12)
            ->name('Marsh')
            ->slug('marsh')
            ->enrollmentId('enr')
            ->enrollmentStatus('connected')
            ->provider('teller')
            ->build());
        $secrets->saveCiphertext((int) $kingdom->getId(), (new TokenCipher('k'))->encrypt('token'));
        $accounts->save(AccountRecord::builder()->kingdomId((int) $kingdom->getId())->tellerAccountId('acc')->name('Checking')->published(true)->build());
        $sync = Strategies::synchronizer(
            $kingdoms,
            $accounts,
            $secrets,
            $transactions,
            Strategies::providers(Strategies::teller()),
            new TokenCipher('k'),
            new \DateTimeImmutable('2026-09-10'),
            Strategies::months(),
        );
        $this->assertTrue($sync->sync(12));
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Debug, 'ledger_sync_backfill_amnesty', TransactionSynchronizer::class . '::sync');
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Info, 'ledger_backfill_completed', TransactionSynchronizer::class . '::sync');
    }
}
