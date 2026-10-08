<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Utilities\Auth\BootstrapAdmins;
use Amtgard\Denarius\Utilities\Auth\ClaimOrn;
use Amtgard\Denarius\Utilities\Auth\DenariusAuthorizer;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\Readiness\Impl\AlwaysReady;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\Registry\LedgerProviderRegistry;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\Support\PreviousMonthWindow;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\SimpleFinApi;
use Amtgard\Denarius\Controller\ManagerController;
use Amtgard\Denarius\Utilities\Http\TwigHtmlRenderer;
use Amtgard\Denarius\Persistence\Record\AccountRecord;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Tests\Support\MethodLogAssert;
use Amtgard\Denarius\Utilities\Log\BranchLogLevel;
use Amtgard\Denarius\Utilities\Security\TokenCipher;
use Amtgard\Denarius\Service\Enrollment\BankConnect;
use Amtgard\Denarius\Service\Enrollment\EnrollmentService;
use Amtgard\Denarius\Service\Kingdom\KingdomSettings;
use Amtgard\Denarius\Service\Access\PermissionService;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\SimpleFinApplicationConfig;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\SimpleFinLedgerProvider;
use Amtgard\Denarius\Service\Enrollment\SimpleFinConnectSession;
use Amtgard\IdpClient\OAuth\TokenSet;
use Amtgard\IdpClient\Resource\AuthenticatedSession;
use Amtgard\IdpClient\Resource\OrkProfile;
use Amtgard\IdpClient\Resource\UserProfile;
use Amtgard\IdpClient\Session\SessionAuthStore;
use Amtgard\PHPUnit\AmtgardTestCase;
use DateTimeImmutable;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class ManageConnectTest extends AmtgardTestCase
{
    private ManagerController $manager;

    private MemoryTransactions $transactions;

    private MemoryKingdoms $kingdoms;

    private MemoryAccounts $accounts;

    protected function setUp(): void
    {
        $_SESSION['_csrf'] = 'token';
        $_SESSION['test_session'] = (new AuthenticatedSession(
            new TokenSet('a'),
            new UserProfile('9', 'person@example.com', 'jwt', OrkProfile::fromArray(['kingdom_id' => 4, 'kingdom_name' => 'Golden Plains'])),
        ))->toSessionArray();

        $kingdoms = new MemoryKingdoms();
        $this->kingdoms = $kingdoms;
        $kingdoms->save(KingdomRecord::builder()
            ->orkKingdomId(4)
            ->name('Golden Plains')
            ->slug('golden-plains')
            ->visibility('public')
            ->displayMode('all')
            ->institutionName('First Credit Union')
            ->provider('teller')
            ->enrollmentId('enr_9')
            ->enrollmentStatus('disconnected')
            ->build());
        $accounts = new MemoryAccounts();
        $this->accounts = $accounts;
        $accounts->save(AccountRecord::builder()->kingdomId(1)->tellerAccountId('acc')->name('Checking')->type('depository')->published(true)->build());
        $permissions = new PermissionService(new FakePolicies([ClaimOrn::admin()]), new ArrayStore(), new DenariusAuthorizer(), BootstrapAdmins::fromEnv(null));
        $queue = new MemoryRefresh();
        $providers = new LedgerProviderRegistry([
            Strategies::teller(),
            new SimpleFinLedgerProvider(new class implements SimpleFinApi {
                public function claim(string $claimUrl): string
                {
                    return '';
                }

                public function accounts(string $accessUrl, int $startsAt, int $endsAt): array
                {
                    return [];
                }
            }, new AlwaysReady(), new PreviousMonthWindow(new DateTimeImmutable('2026-09-28')), new SimpleFinApplicationConfig('amtgard_denarius_dev', 'token', 'https://bridge.simplefin.org/simplefin')),
        ]);
        $transactions = new MemoryTransactions();
        $this->transactions = $transactions;
        $this->manager = new ManagerController(
            new SessionAuthStore('test_session'),
            $permissions,
            $kingdoms,
            $accounts,
            Strategies::kingdomSettings($kingdoms),
            new EnrollmentService($kingdoms, $secrets = new MemorySecrets(), $accounts, $providers, new TokenCipher('k'), $queue, Strategies::months(), Strategies::bankReset($transactions, $accounts, $secrets)),
            $queue,
            new TwigHtmlRenderer(new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'))),
            new BankConnect($providers),
            new SimpleFinConnectSession(),
            Strategies::reviewQueue($transactions, $accounts),
            Strategies::reviewService($transactions, $accounts),
            Strategies::categorySearch(),
            Strategies::kingdomPatternService($kingdoms, $transactions),
            Strategies::patternPrefill(),
            Strategies::ledgerSyncFeedback(),
        );
    }

    public function testAddBankMountsProvidersInOrderThenStops(): void
    {
        $shown = $this->body($this->manager->show($this->request('GET', '/manage/golden-plains'), new Response(), 'golden-plains'));
        $this->assertStringContainsString('Add bank', $shown);
        $this->assertStringNotContainsString('Find my bank', $shown);
        $this->assertStringNotContainsString('Refresh transactions', $shown);
        $this->assertStringContainsString('Bank access was disconnected', $shown);
        $this->assertStringNotContainsString('Connect with Teller', $shown);
        $this->assertStringContainsString('Checking', $shown);

        $teller = $this->body($this->manager->connect($this->request('POST', '/manage/golden-plains/connect', [
            'csrf' => 'token',
        ]), new Response(), 'golden-plains'));
        $this->assertStringContainsString('Connect with Teller', $teller);
        $this->assertStringContainsString('app_test', $teller);
        $this->assertStringContainsString('sandbox', $teller);
        $this->assertStringContainsString('enrollmentId: "enr_9"', $teller);
        $this->assertStringContainsString('Try another provider', $teller);

        $simple = $this->body($this->manager->connect($this->request('POST', '/manage/golden-plains/connect', [
            'csrf' => 'token',
            'current' => 'teller',
            'skip' => '1',
        ]), new Response(), 'golden-plains'));
        $this->assertStringContainsString('simplefin-connect', $simple);
        $this->assertStringContainsString('amtgard_denarius_dev', $simple);
        $this->assertStringContainsString('apps', $simple);

        $again = $this->body($this->manager->connect($this->request('POST', '/manage/golden-plains/connect', [
            'csrf' => 'token',
            'skipped' => ['teller', 'teller', ''],
            'current' => 'simplefin',
            'skip' => '1',
        ]), new Response(), 'golden-plains'));
        $this->assertStringContainsString('No configured provider is available', $again);

        $this->assertSame(403, $this->manager->connect($this->request('POST', '/connect', ['csrf' => 'nope']), new Response(), 'golden-plains')->getStatusCode());
        $this->assertSame(404, $this->manager->connect($this->request('POST', '/missing', ['csrf' => 'token']), new Response(), 'missing')->getStatusCode());
        $guestTransactions = new MemoryTransactions();
        $guestAccounts = new MemoryAccounts();
        $guest = new ManagerController(
            new SessionAuthStore('empty'),
            new PermissionService(new FakePolicies([]), new ArrayStore(), new DenariusAuthorizer(), BootstrapAdmins::fromEnv(null)),
            new MemoryKingdoms(),
            $guestAccounts,
            Strategies::kingdomSettings(new MemoryKingdoms()),
            new EnrollmentService(new MemoryKingdoms(), new MemorySecrets(), $guestAccounts, Strategies::providers(Strategies::teller()), new TokenCipher('k'), new MemoryRefresh(), Strategies::months(), Strategies::bankReset()),
            new MemoryRefresh(),
            new TwigHtmlRenderer(new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'))),
            new BankConnect(Strategies::providers(Strategies::teller())),
            new SimpleFinConnectSession(),
            Strategies::reviewQueue($guestTransactions, $guestAccounts),
            Strategies::reviewService($guestTransactions, $guestAccounts),
            Strategies::categorySearch(),
            Strategies::kingdomPatternService(new MemoryKingdoms(), $guestTransactions),
            Strategies::patternPrefill(),
            Strategies::ledgerSyncFeedback(),
        );
        $this->assertSame(302, $guest->connect($this->request('POST', '/manage/golden-plains/connect', ['csrf' => 'token']), new Response(), 'golden-plains')->getStatusCode());
    }

    public function testReviewQueueDefaultsToLatestMonthAndRedirectsKeepMonth(): void
    {
        $this->transactions->upsert(TransactionRecord::builder()
            ->kingdomId(1)
            ->tellerTransactionId('aug-row')
            ->tellerAccountId('acc')
            ->postedOn('2026-08-14')
            ->amountCents(-100)
            ->description('August supplies')
            ->category('expense.feast_groceries')
            ->publishableAfter('2026-08-01T00:00:00+00:00')
            ->build());

        $latest = $this->body($this->manager->show($this->request('GET', '/manage/golden-plains'), new Response(), 'golden-plains'));
        $this->assertStringContainsString('Showing transactions posted in 2026-08.', $latest);
        $this->assertStringContainsString('August supplies', $latest);
        $this->assertStringContainsString('review_month=2026-07', $latest);
        $this->assertStringContainsString('review_month=2026-09', $latest);

        $july = $this->body($this->manager->show(
            $this->request('GET', '/manage/golden-plains')->withQueryParams(['review_month' => '2026-07', 'uncategorized' => '1']),
            new Response(),
            'golden-plains',
        ));
        $this->assertStringContainsString('Showing transactions posted in 2026-07.', $july);
        $this->assertStringContainsString('No transactions on published accounts for this month.', $july);
        $this->assertStringContainsString('review_month=2026-06&amp;uncategorized=1', $july);

        $published = $this->manager->publishTransaction($this->request('POST', '/manage/golden-plains/transactions/publish', [
            'csrf' => 'token',
            'teller_transaction_id' => 'aug-row',
            'review_month' => '2026-08',
        ]), new Response(), 'golden-plains');
        $this->assertSame('/manage/golden-plains?review_month=2026-08', $published->getHeaderLine('Location'));

        $refreshed = $this->manager->refresh($this->request('POST', '/manage/golden-plains/refresh', ['csrf' => 'token']), new Response(), 'golden-plains');
        $this->assertSame('/manage/golden-plains', $refreshed->getHeaderLine('Location'));
    }

    public function testBatchReviewFormIsNotNestedAndAppliesSelections(): void
    {
        $this->transactions->upsert(TransactionRecord::builder()
            ->kingdomId(1)
            ->tellerTransactionId('batch-row')
            ->tellerAccountId('acc')
            ->postedOn('2026-08-14')
            ->amountCents(-100)
            ->description('Batch supplies')
            ->category('expense.feast_groceries')
            ->publishableAfter('2026-08-01T00:00:00+00:00')
            ->build());

        $page = $this->body($this->manager->show($this->request('GET', '/manage/golden-plains'), new Response(), 'golden-plains'));
        $this->assertStringContainsString('name="review[batch-row][publish]" value="1" form="review-batch-form"', $page);
        $this->assertStringContainsString('name="review_id[]" value="batch-row" form="review-batch-form"', $page);
        $batchStart = strpos($page, '<form id="review-batch-form"');
        $this->assertNotFalse($batchStart);
        $this->assertGreaterThan(strrpos($page, '</table>'), $batchStart);
        $batchForm = substr($page, $batchStart, strpos($page, '</form>', $batchStart) - $batchStart);
        $this->assertSame(1, substr_count($batchForm, '<form'));
        $this->assertStringContainsString('name="review_month" value="2026-08"', $batchForm);

        $response = $this->manager->updateTransactionReview($this->request('POST', '/manage/golden-plains/transactions/review', [
            'csrf' => 'token',
            'review_month' => '2026-08',
            'review_id' => ['batch-row'],
            'review' => ['batch-row' => ['redact' => '1']],
        ]), new Response(), 'golden-plains');
        $this->assertSame('/manage/golden-plains?review_month=2026-08', $response->getHeaderLine('Location'));
        $this->assertNotNull($this->transactions->findByTellerTransactionId('batch-row')?->getPublishedAt());
        $this->assertSame(403, $this->manager->updateTransactionReview($this->request('POST', '/manage/golden-plains/transactions/review', [
            'csrf' => 'nope',
        ]), new Response(), 'golden-plains')->getStatusCode());
    }

    public function testConnectedKingdomShowsDisconnectAndResetClearsBankData(): void
    {
        $kingdom = $this->kingdoms->findBySlug('golden-plains');
        $this->assertNotNull($kingdom);
        $this->kingdoms->save(\Amtgard\Denarius\Domain\Kingdom\KingdomRecordRebuilder::from($kingdom)
            ->enrollmentStatus('connected')
            ->lastSyncStatus('ok')
            ->build());
        $this->transactions->upsert(TransactionRecord::builder()
            ->kingdomId(1)
            ->tellerTransactionId('old-bank-row')
            ->tellerAccountId('acc')
            ->postedOn('2026-08-14')
            ->amountCents(-100)
            ->category('expense.feast_groceries')
            ->build());

        $shown = $this->body($this->manager->show($this->request('GET', '/manage/golden-plains'), new Response(), 'golden-plains'));
        $this->assertStringContainsString('Connected to <span class="fw-semibold">First Credit Union</span> via teller.', $shown);
        $this->assertStringContainsString('action="/manage/golden-plains/disconnect"', $shown);
        $this->assertStringNotContainsString('Add bank', $shown);

        $this->assertSame(403, $this->manager->disconnectBank($this->request('POST', '/manage/golden-plains/disconnect', ['csrf' => 'nope']), new Response(), 'golden-plains')->getStatusCode());
        MethodLogAssert::reset();
        $response = $this->manager->disconnectBank($this->request('POST', '/manage/golden-plains/disconnect', ['csrf' => 'token']), new Response(), 'golden-plains');

        $this->assertSame('/manage/golden-plains', $response->getHeaderLine('Location'));
        $reset = $this->kingdoms->findBySlug('golden-plains');
        $this->assertSame('disconnected', $reset?->getEnrollmentStatus());
        $this->assertNull($reset?->getEnrollmentId());
        $this->assertNull($reset?->getProvider());
        $this->assertNull($reset?->getLastSyncStatus());
        $this->assertNull($this->transactions->findByTellerTransactionId('old-bank-row'));
        $this->assertSame([], $this->accounts->forKingdom(1));
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Info, 'enrollment_bank_disconnected', EnrollmentService::class . '::disconnectBank');

        $after = $this->body($this->manager->show($this->request('GET', '/manage/golden-plains'), new Response(), 'golden-plains'));
        $this->assertStringContainsString('Add bank', $after);
    }

    public function testStripeAndPlaidWidgetsRenderFromConnectConfig(): void
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'));
        $kingdom = [
            'name' => 'Golden Plains',
            'slug' => 'golden-plains',
            'visibility' => 'public',
            'displayMode' => 'all',
            'enrollmentStatus' => 'none',
            'provider' => '',
            'enrollmentId' => null,
        ];
        $stripe = $twig->render('manage.twig', [
            'csrf' => 'token',
            'kingdom' => $kingdom,
            'accounts' => [],
            'reviewQueue' => [],
            'connect' => [
                'available' => true,
                'autostart' => true,
                'reason' => '',
                'provider' => 'stripe',
                'skipped' => [],
                'config' => ['publishableKey' => 'pk_test', 'clientSecret' => 'cs_test', 'customerId' => 'cus_1'],
            ],
        ]);
        $this->assertStringContainsString('Connect with Stripe', $stripe);
        $this->assertStringContainsString('pk_test', $stripe);
        $this->assertStringContainsString('cus_1', $stripe);

        $missingKey = $twig->render('connect/stripe.twig', [
            'connect' => ['config' => ['publishableKey' => '']],
        ]);
        $this->assertStringContainsString('Stripe is missing a publishable key.', $missingKey);

        $plaid = $twig->render('manage.twig', [
            'csrf' => 'token',
            'kingdom' => $kingdom,
            'accounts' => [],
            'reviewQueue' => [],
            'connect' => [
                'available' => true,
                'autostart' => true,
                'reason' => '',
                'provider' => 'plaid',
                'skipped' => [],
                'config' => ['linkToken' => 'link-sandbox'],
            ],
        ]);
        $this->assertStringContainsString('Connect with Plaid', $plaid);
        $this->assertStringContainsString('link-sandbox', $plaid);
    }

    private function request(string $method, string $path, array $body = []): \Psr\Http\Message\ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path);
        if ($body !== []) {
            $request = $request->withParsedBody($body);
        }

        return $request;
    }

    private function body(\Psr\Http\Message\ResponseInterface $response): string
    {
        return (string) $response->getBody();
    }
}
