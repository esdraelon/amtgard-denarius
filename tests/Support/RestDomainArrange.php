<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Support;

use Amtgard\Denarius\Utilities\Log\IdpHttpTrafficLog;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;

use Amtgard\Denarius\Domain\Kingdom\KingdomRecordRebuilder;
use Amtgard\Denarius\Domain\Statement\MonthWindow;
use Amtgard\Denarius\Domain\Statement\Publication\PublicationEmbargoCalculator;
use Amtgard\Denarius\Domain\Statement\Publication\PublicationSettingsValidator;
use Amtgard\Denarius\Domain\Statement\Publication\TransactionRecordRebuilder;
use Amtgard\Denarius\Domain\Statement\Publication\TransactionReviewRow;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Tests\Unit\ApplicationTest;
use Amtgard\Denarius\Tests\Unit\Log\LoggingCoreTest;
use Amtgard\Denarius\Tests\Unit\ProviderSetupTest;

/** Memory fakes and existing unit tests for statement, setup, queue, session, and security method-log coverage. */
final class RestDomainArrange
{
    public static function exerciseAll(): void
    {
        self::run(ApplicationTest::class, 'testSlugMonthMoneyAndStatementModes');
        self::run(ApplicationTest::class, 'testRoleAdminSettingsEnrollmentSyncAndWebhooks');
        self::run(ApplicationTest::class, 'testDirectoryQueueWorkerAndHttpClients');
        self::exercisePublicationDomain();
        self::exercisePublicationPipeline();
        self::exercisePublicationHardRedact();
        self::run(\Amtgard\Denarius\Tests\Unit\PublicationPublicReadTest::class, 'testPublicStatementOmitsUnpublishedTransactions');
        self::run(\Amtgard\Denarius\Tests\Unit\StatementAbsenceTest::class, 'testAbsenceReasonSerializesForCache');
        self::run(\Amtgard\Denarius\Tests\Unit\StatementAbsenceTest::class, 'testClassifierMarksUnreviewedWhenEmbargoClearedButUnpublished');
        self::run(\Amtgard\Denarius\Tests\Unit\StatementAbsenceTest::class, 'testClassifierMarksStaleWhenMonthActivityStillEmbargoed');
        self::run(\Amtgard\Denarius\Tests\Unit\StatementAbsenceTest::class, 'testClassifierMarksNoCurrentSinceWhenMonthIsEmpty');
        self::run(\Amtgard\Denarius\Tests\Unit\StatementAbsenceTest::class, 'testAbsenceReasonRoundTripsThroughMonthCache');
        self::run(\Amtgard\Denarius\Tests\Unit\StatementAbsenceTest::class, 'testPublicKingdomQueryAttachesAbsenceWhenRowsEmpty');
        self::exerciseTransactionReviewDomain();

        MonthWindow::current(new \DateTimeImmutable('2026-09-15'));

        self::run(ProviderSetupTest::class, 'testVerifiedProvidersAreWrittenWithoutPrintingSecrets');
        self::run(ProviderSetupTest::class, 'testARejectedProviderIsOmittedAndAnEmptyRunWritesNothing');
        self::run(ProviderSetupTest::class, 'testGuidesDescribeTheHumanSteps');
        self::run(ProviderSetupTest::class, 'testHiddenLineAndEnvFragmentKeepSecretsOutOfTheTerminal');
        self::run(ProviderSetupTest::class, 'testConsoleIoHidesInputOnlyOnATerminal');
        self::run(ProviderSetupTest::class, 'testCurlClientUsesAFetcherOrTheNetwork');
    }

    public static function exerciseUtilitiesLog(): void
    {
        self::run(LoggingCoreTest::class, 'testRequestLogContext');
        self::run(LoggingCoreTest::class, 'testCorrelationMiddlewareAcceptsAndRejectsRequestId');
        $_ENV['APP_DEBUG'] = 'true';
        $_ENV['DENARIUS_IDP_HTTP_LOG'] = 'true';
        IdpHttpTrafficLog::record(
            new Request('GET', 'https://idp.example.test/ping', ['Authorization' => ['Basic x']]),
            new Response(200, [], '{}'),
        );
    }

    private static function exercisePublicationDomain(): void
    {
        $now = new \DateTimeImmutable('2026-09-10T12:00:00+00:00');
        $calculator = new PublicationEmbargoCalculator();
        $calculator->publishableAfter('2026-09-01', 3, $now, false);
        $calculator->publishableAfter('2026-09-01', 3, $now, true);
        $calculator->publishableAfter('2026-09-09', 3, $now, true);
        (new PublicationSettingsValidator())->clampEmbargoDays(99);
        KingdomRecordRebuilder::from(KingdomRecord::builder()->orkKingdomId(1)->name('Alpha')->slug('alpha')->build());
    }

    private static function exerciseTransactionReviewDomain(): void
    {
        $stored = TransactionRecord::builder()
            ->kingdomId(1)
            ->tellerTransactionId('review-tx')
            ->tellerAccountId('acc')
            ->postedOn('2026-09-02')
            ->amountCents(-100)
            ->category('general')
            ->build();
        TransactionRecordRebuilder::from($stored)->publishedAt('2026-09-03T00:00:00+00:00')->build();
        (new TransactionReviewRow('review-tx', '2026-09-02', '-$1.00', 'Supplies', 'Checking', 'pending'))->view();
    }

    private static function exercisePublicationPipeline(): void
    {
        self::run(\Amtgard\Denarius\Tests\Unit\PublicationPipelineTest::class, 'testEmbargoStageDropsLinesBeforePublishableAfter');
        self::run(\Amtgard\Denarius\Tests\Unit\PublicationPipelineTest::class, 'testPublicationStatusStageDropsUnpublishedLines');
        self::run(\Amtgard\Denarius\Tests\Unit\PublicationPipelineTest::class, 'testManagerQueryIncludesUnpublishedLines');
        self::run(\Amtgard\Denarius\Tests\Unit\PublicationPipelineTest::class, 'testPublicPipelineRunsFullStageChain');
    }

    private static function exercisePublicationHardRedact(): void
    {
        self::run(\Amtgard\Denarius\Tests\Unit\PublicationHardRedactTest::class, 'testVerifyBrandKeywordMarksHard');
        self::run(\Amtgard\Denarius\Tests\Unit\PublicationHardRedactTest::class, 'testKeywordIngestMarksHardFlags');
        self::run(\Amtgard\Denarius\Tests\Unit\PublicationHardRedactTest::class, 'testMicroDepositPairReconcilerMarksCluster');
        self::run(\Amtgard\Denarius\Tests\Unit\PublicationHardRedactTest::class, 'testHardRedactionStageStubsLine');
        self::run(\Amtgard\Denarius\Tests\Unit\PublicationHardRedactTest::class, 'testPublicationFlagsRoundTrip');
    }

    /**
     * @param class-string $class
     */
    private static function run(string $class, string $method): void
    {
        (new $class($method))->{$method}();
    }
}
