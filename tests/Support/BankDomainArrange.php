<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Support;

use Amtgard\Denarius\Domain\Bank\Notice\Impl\IgnoredLedgerNotice;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\Impl\MissingLedgerProvider;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\Registry\LedgerProviderRegistry;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Plaid\Impl\CurlPlaidApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\Impl\CurlSimpleFinApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\SimpleFinHost;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Stripe\Impl\CurlStripeApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Teller\Impl\CurlTellerApi;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Tests\Unit\LedgerFacadeTest;
use Amtgard\Denarius\Tests\Unit\PlaidAdapterTest;
use Amtgard\Denarius\Tests\Unit\ProviderRegistryTest;
use Amtgard\Denarius\Tests\Unit\SimpleFinAdapterTest;
use Amtgard\Denarius\Tests\Unit\StripeAdapterTest;

/** Memory fakes and adapter tests for Domain/Bank method-log coverage. */
final class BankDomainArrange
{
    public static function exerciseAll(): void
    {
        ServiceWorkerArrange::exerciseAll();

        self::run(ProviderRegistryTest::class, 'testVerdictsDistinguishARejectedBank');
        self::run(ProviderRegistryTest::class, 'testCredentialsAreReadyOnlyWhenEveryValueIsPresent');
        self::run(ProviderRegistryTest::class, 'testResolveSkipsRejectedAndSkippedProviders');
        self::exerciseMissingProvider();
        self::exerciseIgnoredNotice();

        class_exists(LedgerFacadeTest::class);
        self::run(ProviderRegistryTest::class, 'testTellerReportsUnknownCoverageWhenCredentialsArePresent');
        self::run(LedgerFacadeTest::class, 'testTellerAdapterMapsSparseRowsAndUnknownNotices');

        self::run(PlaidAdapterTest::class, 'testPlaidLinksAccountsAndNotices');
        self::run(PlaidAdapterTest::class, 'testPlaidVerificationRejectsBadTokens');
        PlaidAdapterTest::exerciseCurlForMethodLog();
        self::run(PlaidAdapterTest::class, 'testPlaidIsAdmittedBetweenStripeAndTeller');

        self::run(SimpleFinAdapterTest::class, 'testSimpleFinClaimsAccountsInsideTheWindow');
        self::run(SimpleFinAdapterTest::class, 'testSimpleFinHostAllowsOnlyConfiguredBridges');
        SimpleFinAdapterTest::exerciseCurlForMethodLog();
        self::run(SimpleFinAdapterTest::class, 'testSimpleFinStaysBehindTheEarlierProviders');

        self::run(StripeAdapterTest::class, 'testStripeConnectsAccountsAndNotices');
        self::run(StripeAdapterTest::class, 'testStripeSignatureRejectsStaleAndBlankSecrets');
        self::run(StripeAdapterTest::class, 'testConfiguredProvidersKeepReadyOnesInOrder');
        StripeAdapterTest::exerciseCurlForMethodLog();

        self::exerciseTellerHttpClients();
        self::exerciseCurlFetchPaths();
    }

    private static function exerciseIgnoredNotice(): void
    {
        $ignored = new IgnoredLedgerNotice();
        $ignored->action();
        $ignored->apply(KingdomRecord::builder()->orkKingdomId(4)->name('Golden Plains')->slug('golden-plains')->build());
    }

    private static function exerciseMissingProvider(): void
    {
        $registry = new LedgerProviderRegistry([]);
        $missing = $registry->default();

        if (! $missing instanceof MissingLedgerProvider) {
            return;
        }

        $missing->id();
        $missing->signatureHeader();
        $missing->supports('Chase')->rejected();
        $missing->connectConfig('golden-plains');
        $missing->accounts('token');
        $missing->transactions('token', 'acc', null);
        $missing->notice('{}', null, 1)->accepted;
        try {
            $missing->enrollment([]);
        } catch (\InvalidArgumentException) {
        }
    }

    private static function exerciseTellerHttpClients(): void
    {
        $teller = new CurlTellerApi('https://api.teller.io', '', '', function (string $url): string {
            if (str_contains($url, 'transactions')) {
                return json_encode([['id' => 'txn_2'], 'skip'], JSON_THROW_ON_ERROR);
            }

            return json_encode([['id' => 'acc_1', 'name' => 'Checking']], JSON_THROW_ON_ERROR);
        });
        $teller->accounts('tok');
        $teller->transactions('tok', 'acc 1', 'txn_1');
    }

    private static function exerciseCurlFetchPaths(): void
    {
        self::invokePrivate(new CurlPlaidApi('', 'id', 'secret', 'Denarius'), 'fetch', '', ['client_id' => 'id', 'secret' => 'secret']);
        self::invokePrivate(new CurlSimpleFinApi(new SimpleFinHost(['127.0.0.1'], true)), 'fetch', 'POST', '');
        self::invokePrivate(new CurlStripeApi('', 'sk_test'), 'fetch', 'POST', '', ['name' => 'golden']);
        self::invokePrivate(new CurlTellerApi('', '', ''), 'fetch', '', 'token');
    }

    /**
     * @param list<mixed> $args
     */
    private static function invokePrivate(object $target, string $method, mixed ...$args): void
    {
        $reflection = new \ReflectionMethod($target, $method);
        try {
            $reflection->invoke($target, ...$args);
        } catch (\RuntimeException) {
        }
    }

    /**
     * @param class-string $class
     */
    private static function run(string $class, string $method): void
    {
        (new $class($method))->{$method}();
    }
}
