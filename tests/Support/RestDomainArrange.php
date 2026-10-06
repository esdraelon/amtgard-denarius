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
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
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
        self::run(\Amtgard\Denarius\Tests\Unit\PublicationPublicReadTest::class, 'testPublicStatementOmitsUnpublishedTransactions');

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

    /**
     * @param class-string $class
     */
    private static function run(string $class, string $method): void
    {
        (new $class($method))->{$method}();
    }
}
