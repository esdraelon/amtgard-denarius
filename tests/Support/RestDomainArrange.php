<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Support;

use Amtgard\Denarius\Domain\Statement\MonthWindow;
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
    }

    /**
     * @param class-string $class
     */
    private static function run(string $class, string $method): void
    {
        (new $class($method))->{$method}();
    }
}
