<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit\Log;

use Amtgard\Denarius\Controller\AdminController;
use Amtgard\Denarius\Controller\HomeController;
use Amtgard\Denarius\Controller\KingdomPageController;
use Amtgard\Denarius\Controller\ManagerController;
use Amtgard\Denarius\Controller\WebhookController;
use Amtgard\Denarius\Domain\Access\KingdomAccess;
use Amtgard\Denarius\Domain\Statement\MonthStatementBuilder;
use Amtgard\Denarius\Persistence\Record\AccountRecord;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Persistence\Record\PrincipalRecord;
use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Service\Access\AccountNavBuilder;
use Amtgard\Denarius\Service\Access\PermissionService;
use Amtgard\Denarius\Service\Access\PrincipalSync;
use Amtgard\Denarius\Service\Enrollment\BankConnect;
use Amtgard\Denarius\Service\Enrollment\EnrollmentService;
use Amtgard\Denarius\Tests\Support\KingdomPageQueryFactory;
use Amtgard\Denarius\Service\Kingdom\KingdomSettings;
use Amtgard\Denarius\Service\Ledger\ProviderWebhookHandler;
use Amtgard\Denarius\Tests\Support\MethodLogAssert;
use Amtgard\Denarius\Tests\Support\TracedMethodCatalog;
use Amtgard\Denarius\Tests\Unit\ApplicationTest;
use Amtgard\Denarius\Tests\Unit\ArrayStore;
use Amtgard\Denarius\Tests\Unit\FakePolicies;
use Amtgard\Denarius\Tests\Unit\MemoryAccounts;
use Amtgard\Denarius\Tests\Unit\MemoryGrants;
use Amtgard\Denarius\Tests\Unit\MemoryKingdoms;
use Amtgard\Denarius\Tests\Unit\MemoryPrincipals;
use Amtgard\Denarius\Tests\Unit\MemoryRefresh;
use Amtgard\Denarius\Tests\Unit\MemorySecrets;
use Amtgard\Denarius\Tests\Unit\MemoryTransactions;
use Amtgard\Denarius\Tests\Unit\Strategies;
use Amtgard\Denarius\Utilities\Auth\BootstrapAdmins;
use Amtgard\Denarius\Utilities\Auth\ClaimOrn;
use Amtgard\Denarius\Utilities\Auth\DenariusAuthorizer;
use Amtgard\Denarius\Utilities\Http\LoggingIdpHttpClient;
use Amtgard\Denarius\Utilities\Http\OrkKingdomDirectory;
use Amtgard\Denarius\Utilities\Http\PostCsrfMiddleware;
use Amtgard\Denarius\Utilities\Http\SyncPrincipalMiddleware;
use Amtgard\Denarius\Utilities\Http\TwigHtmlRenderer;
use Amtgard\Denarius\Utilities\Security\TokenCipher;
use Amtgard\IdpClient\OAuth\TokenSet;
use Amtgard\IdpClient\Resource\AuthenticatedSession;
use Amtgard\IdpClient\Resource\OrkProfile;
use Amtgard\IdpClient\Resource\UserProfile;
use Amtgard\IdpClient\Session\SessionAuthStore;
use Amtgard\PHPUnit\AmtgardTestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class TracedHttpMethodsTest extends AmtgardTestCase
{
    public function testEveryControllerAndHttpTraceSiteIsAsserted(): void
    {
        MethodLogAssert::resetTraces();
        class_exists(ApplicationTest::class);

        $twig = new TwigHtmlRenderer(new Environment(new ArrayLoader([
            'home.twig' => 'home',
            'message.twig' => '{{ title }}',
            'kingdom.twig' => '{{ mode }} {{ rows|length }}',
            'admin.twig' => 'admin',
            'manage.twig' => 'manage',
            'privacy-policy.twig' => 'privacy',
            'simplefin-return.twig' => 'simplefin-return',
        ])));
        $auth = new SessionAuthStore('test_session');
        $root = sys_get_temp_dir() . '/denarius-log-http-' . uniqid();
        mkdir($root);
        file_put_contents($root . '/VERSION', "1\n");

        $kingdoms = new MemoryKingdoms();
        $permissions = new PermissionService(new FakePolicies([]), new ArrayStore(), new DenariusAuthorizer(), BootstrapAdmins::fromEnv(null));
        $home = new HomeController($twig, $auth, new AccountNavBuilder($permissions, $kingdoms), $root);
        $home->home($this->request('GET', '/'), new Response());
        $home->version($this->request('GET', '/version'), new Response());
        $home->privacyPolicy($this->request('GET', '/privacy-policy'), new Response());
        $kingdom = $kingdoms->save(KingdomRecord::builder()
            ->orkKingdomId(4)
            ->name('Golden Plains')
            ->slug('golden-plains')
            ->visibility('public')
            ->displayMode('summarized')
            ->enrollmentStatus('connected')
            ->institutionName('Example Bank')
            ->build());
        $accounts = new MemoryAccounts();
        $accounts->save(AccountRecord::builder()->kingdomId(1)->tellerAccountId('acc')->name('Checking')->published(true)->build());
        $transactions = new MemoryTransactions();
        $transactions->upsert(TransactionRecord::builder()->kingdomId(1)->tellerTransactionId('t')->tellerAccountId('acc')->postedOn('2026-09-02')->amountCents(250)->category('office')->publishedAt('2026-09-03T00:00:00+00:00')->build());
        $pages = KingdomPageQueryFactory::publicRead($transactions, $accounts);
        $page = new KingdomPageController($kingdoms, $pages, KingdomAccess::standard(), $auth, $twig);
        $page->show($this->request('GET', '/missing'), new Response(), 'missing');

        $_SESSION['test_session'] = (new AuthenticatedSession(
            new TokenSet('a'),
            new UserProfile('9', 'person@example.com', 'jwt', OrkProfile::fromArray(['kingdom_id' => 4, 'kingdom_name' => 'Golden Plains'])),
        ))->toSessionArray();
        $managerPermissions = new PermissionService(
            new FakePolicies([ClaimOrn::manage(4)]),
            new ArrayStore(),
            new DenariusAuthorizer(),
            BootstrapAdmins::fromEnv(null),
        );
        (new HomeController($twig, $auth, new AccountNavBuilder($managerPermissions, $kingdoms), $root))
            ->home($this->request('GET', '/'), new Response());
        $page->show($this->request('GET', '/golden-plains', ['month' => '2026-09']), new Response(), 'golden-plains');
        $kingdoms->save(KingdomRecord::builder()->id($kingdom->getId())->orkKingdomId(4)->name('Golden Plains')->slug('golden-plains')->visibility('public')->displayMode('all')->enrollmentStatus('connected')->build());
        $page->show($this->request('GET', '/golden-plains', ['month' => '2026-09']), new Response(), 'golden-plains');

        $permissions = new PermissionService(new FakePolicies([ClaimOrn::admin()]), new ArrayStore(), new DenariusAuthorizer(), BootstrapAdmins::fromEnv(null));
        $principals = new MemoryPrincipals();
        $principals->save(PrincipalRecord::builder()->idpUserId('9')->email('person@example.com')->build());
        $principals->save(PrincipalRecord::builder()->idpUserId('31786326')->email('legacy@example.com')->build());
        $orkKingdoms = Strategies::orkKingdoms($kingdoms, $principals);
        $grantTargets = Strategies::grantTargets($principals);
        $grants = new MemoryGrants();
        $grantedRoles = Strategies::grantedRoles($grants, $principals, $kingdoms);
        $admin = new AdminController($auth, $permissions, $principals, $kingdoms, new FakePolicies([]), $grants, $twig, Strategies::admin(), $orkKingdoms, $grantTargets, $grantedRoles);
        (new AdminController(new SessionAuthStore('empty'), $permissions, $principals, $kingdoms, new FakePolicies([]), new MemoryGrants(), $twig, Strategies::admin(), $orkKingdoms, $grantTargets, Strategies::grantedRoles(new MemoryGrants(), $principals, $kingdoms)))
            ->index($this->request('GET', '/admin'), new Response());
        $member = new PermissionService(new FakePolicies([]), new ArrayStore(), new DenariusAuthorizer(), BootstrapAdmins::fromEnv(null));
        (new AdminController($auth, $member, $principals, $kingdoms, new FakePolicies([]), new MemoryGrants(), $twig, Strategies::admin(), $orkKingdoms, $grantTargets, Strategies::grantedRoles(new MemoryGrants(), $principals, $kingdoms)))
            ->index($this->request('GET', '/admin'), new Response());
        $admin->index($this->request('GET', '/admin', ['email' => 'person']), new Response());
        $admin->index($this->request('GET', '/admin', ['email' => 'legacy']), new Response());
        $admin->index($this->request('GET', '/admin', ['perm_email' => 'person', 'perm_kingdom' => '']), new Response());
        $admin->kingdoms($this->request('GET', '/admin/kingdoms'), new Response());
        $suggestions = $admin->principalSuggestions($this->request('GET', '/admin/principal-suggestions', ['q' => 'person']), new Response());
        $this->assertSame(200, $suggestions->getStatusCode());
        $admin->principalSuggestions($this->request('GET', '/admin/principal-suggestions', ['q' => '']), new Response());
        (new AdminController(new SessionAuthStore('empty'), $permissions, $principals, $kingdoms, new FakePolicies([]), new MemoryGrants(), $twig, Strategies::admin(), $orkKingdoms, $grantTargets, Strategies::grantedRoles(new MemoryGrants(), $principals, $kingdoms)))
            ->principalSuggestions($this->request('GET', '/admin/principal-suggestions', ['q' => 'person']), new Response());
        $orkKingdoms->list();
        OrkKingdomDirectory::parse('{}');
        OrkKingdomDirectory::normalizeSimpleList([['id' => 1, 'name' => 'Alpha']]);
        $_SESSION['_csrf'] = 'token';
        $admin->grant($this->request('POST', '/admin/grant', [], ['csrf' => 'token', 'idp_user_id' => '9', 'action' => 'grant-manager', 'ork_kingdom_id' => '4', 'kingdom_name' => 'Golden Plains']), new Response());
        $admin->grant($this->request('POST', '/admin/grant', [], [
            'csrf' => 'token',
            'idp_user_id' => '31786326',
            'target_email' => 'legacy@example.com',
            'action' => 'grant-admin',
        ]), new Response());
        $admin->grant($this->request('POST', '/admin/grant', [], ['csrf' => 'nope']), new Response());

        $queue = new MemoryRefresh();
        $connects = new BankConnect(Strategies::providers(Strategies::teller()));
        $manager = new ManagerController(
            $auth,
            $permissions,
            $kingdoms,
            $accounts,
            Strategies::kingdomSettings($kingdoms),
            new EnrollmentService($kingdoms, new MemorySecrets(), $accounts, Strategies::providers(Strategies::teller()), new TokenCipher('k'), $queue, Strategies::months()),
            $queue,
            $twig,
            $connects,
            new \Amtgard\Denarius\Service\Enrollment\SimpleFinConnectSession(),
            Strategies::reviewQueue($transactions, $accounts),
            Strategies::reviewService($transactions, $accounts),
        );
        (new ManagerController(new SessionAuthStore('empty'), $member, $kingdoms, $accounts, Strategies::kingdomSettings($kingdoms), new EnrollmentService($kingdoms, new MemorySecrets(), $accounts, Strategies::providers(Strategies::teller()), new TokenCipher('k'), $queue, Strategies::months()), $queue, $twig, $connects, new \Amtgard\Denarius\Service\Enrollment\SimpleFinConnectSession(), Strategies::reviewQueue($transactions, $accounts), Strategies::reviewService($transactions, $accounts)))
            ->show($this->request('GET', '/manage/golden-plains'), new Response(), 'golden-plains');
        $manager->show($this->request('GET', '/manage/missing'), new Response(), 'missing');
        $manager->show($this->request('GET', '/manage/golden-plains'), new Response(), 'golden-plains');
        $manager->connect($this->request('POST', '/manage/golden-plains/connect', [], ['csrf' => 'token']), new Response(), 'golden-plains');
        $manager->connectGet($this->request('GET', '/manage/golden-plains/connect'), new Response(), 'golden-plains');
        $manager->settings($this->request('POST', '/manage/golden-plains/settings', [], ['csrf' => 'token', 'visibility' => 'public', 'display_mode' => 'all', 'embargo_days' => '3']), new Response(), 'golden-plains');
        $manager->enrollment($this->request('POST', '/manage/golden-plains/enrollment', [], ['csrf' => 'token', 'enrollment' => json_encode(['accessToken' => 'tok', 'id' => 'enr_9'])]), new Response(), 'golden-plains');
        $manager->accounts($this->request('POST', '/manage/golden-plains/accounts', [], ['csrf' => 'token', 'published' => ['acc']]), new Response(), 'golden-plains');
        $manager->refresh($this->request('POST', '/manage/golden-plains/refresh', [], ['csrf' => 'token']), new Response(), 'golden-plains');
        $transactions->upsert(TransactionRecord::builder()->kingdomId(1)->tellerTransactionId('pub-me')->tellerAccountId('acc')->postedOn('2026-09-02')->amountCents(100)->category('uncategorized')->publishableAfter('2026-09-01T00:00:00+00:00')->build());
        $manager->publishTransaction($this->request('POST', '/manage/golden-plains/transactions/publish', [], ['csrf' => 'token', 'teller_transaction_id' => 'pub-me']), new Response(), 'golden-plains');
        $transactions->upsert(TransactionRecord::builder()->kingdomId(1)->tellerTransactionId('pub-me')->tellerAccountId('acc')->postedOn('2026-09-02')->amountCents(100)->category('uncategorized')->publishedAt('2026-09-03T00:00:00+00:00')->build());
        $manager->withholdTransaction($this->request('POST', '/manage/golden-plains/transactions/withhold', [], ['csrf' => 'token', 'teller_transaction_id' => 'pub-me']), new Response(), 'golden-plains');
        $manager->publishTransaction($this->request('POST', '/manage/golden-plains/transactions/publish', [], ['csrf' => 'nope', 'teller_transaction_id' => 'pub-me']), new Response(), 'golden-plains');
        $manager->settings($this->request('POST', '/x', [], ['csrf' => 'bad']), new Response(), 'golden-plains');

        $_ENV['APP_PUBLIC_URL'] = 'http://localhost:37180';
        \Amtgard\Denarius\Utilities\Http\AppPublicUrl::base();
        \Amtgard\Denarius\Utilities\Http\AppPublicUrl::path('/bank/simplefin/return');
        $simplefinSession = new \Amtgard\Denarius\Service\Enrollment\SimpleFinConnectSession();
        $token = base64_encode('https://bridge.simplefin.org/simplefin/claim/trace');
        $simplefinProviders = new \Amtgard\Denarius\Domain\Bank\Provider\Framework\Registry\LedgerProviderRegistry([
            new \Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\SimpleFinLedgerProvider(
                new class implements \Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\SimpleFinApi {
                    public function claim(string $claimUrl): string
                    {
                        return 'https://user:secret@bridge.simplefin.org/simplefin';
                    }

                    public function accounts(string $accessUrl, int $startsAt, int $endsAt): array
                    {
                        return [];
                    }
                },
                new \Amtgard\Denarius\Domain\Bank\Provider\Framework\Readiness\Impl\AlwaysReady(),
                new \Amtgard\Denarius\Domain\Bank\Provider\Framework\Support\PreviousMonthWindow(new \DateTimeImmutable('2026-09-28')),
                new \Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\SimpleFinApplicationConfig('app', 'tok', 'https://bridge.simplefin.org/simplefin'),
            ),
        ]);
        $simplefinReturn = new \Amtgard\Denarius\Controller\SimpleFinReturnController(
            $auth,
            new \Amtgard\Denarius\Service\Enrollment\SimpleFinReturnEnrollment(
                $kingdoms,
                new EnrollmentService($kingdoms, new MemorySecrets(), $accounts, $simplefinProviders, new TokenCipher('k'), $queue, Strategies::months()),
                $simplefinSession,
                $permissions,
            ),
            $twig,
        );
        $simplefinSession->remember('golden-plains');
        $simplefinReturn->show($this->request('GET', '/bank/simplefin/return?setup_token=' . rawurlencode($token)), new Response());
        $simplefinReturn->show($this->request('GET', '/bank/simplefin/return'), new Response());
        $simplefinReturn->submit($this->request('POST', '/bank/simplefin/return', [], ['csrf' => 'nope']), new Response());
        (new \Amtgard\Denarius\Controller\SimpleFinReturnController(new SessionAuthStore('empty'), new \Amtgard\Denarius\Service\Enrollment\SimpleFinReturnEnrollment($kingdoms, new EnrollmentService($kingdoms, new MemorySecrets(), $accounts, Strategies::providers(Strategies::teller()), new TokenCipher('k'), $queue, Strategies::months()), new \Amtgard\Denarius\Service\Enrollment\SimpleFinConnectSession(), $member), $twig))
            ->show($this->request('GET', '/bank/simplefin/return'), new Response());

        $enrollment = new EnrollmentService($kingdoms, new MemorySecrets(), $accounts, Strategies::providers(Strategies::teller()), new TokenCipher('k'), $queue, Strategies::months());
        $handler = new ProviderWebhookHandler(Strategies::providers(Strategies::teller()), $kingdoms, Strategies::events($queue, $enrollment));
        $webhook = new WebhookController($handler);
        $webhook->teller($this->request('POST', '/webhooks/teller'), new Response());
        $webhook->stripe($this->request('POST', '/webhooks/stripe'), new Response());
        $webhook->plaid($this->request('POST', '/webhooks/plaid'), new Response());
        $body = json_encode(['type' => 'enrollment.updated', 'enrollment_id' => 'enr_1']);
        $now = 1_700_000_000;
        $signature = 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . $body, 'whsec');
        $kingdoms->save(KingdomRecord::builder()->id($kingdom->getId())->orkKingdomId(4)->name('Golden Plains')->slug('golden-plains')->enrollmentId('enr_1')->provider('teller')->enrollmentStatus('connected')->build());
        $signed = (new ServerRequestFactory())->createServerRequest('POST', '/webhooks/teller')
            ->withHeader('Teller-Signature', $signature)
            ->withBody((new \Slim\Psr7\Factory\StreamFactory())->createStream((string) $body));
        $webhook->teller($signed, new Response());

        $passThrough = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        };
        $csrfMiddleware = new PostCsrfMiddleware(new ResponseFactory());
        $csrfMiddleware->process($this->request('GET', '/'), $passThrough);
        $_SESSION['_csrf'] = 'token';
        $csrfMiddleware->process($this->request('POST', '/admin/grant', [], ['csrf' => 'token']), $passThrough);
        $csrfMiddleware->process($this->request('POST', '/admin/grant', [], ['csrf' => 'nope']), $passThrough);
        $csrfMiddleware->process($this->request('POST', '/webhooks/teller'), $passThrough);

        $sync = new SyncPrincipalMiddleware($auth, new PrincipalSync($principals));
        $sync->process($this->request('GET', '/'), $passThrough);
        $syncGuest = new SyncPrincipalMiddleware(new SessionAuthStore('empty'), new PrincipalSync($principals));
        $syncGuest->process($this->request('GET', '/'), $passThrough);

        (new LoggingIdpHttpClient(new class implements \Psr\Http\Client\ClientInterface {
            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                return new \Nyholm\Psr7\Response(204);
            }
        }))->sendRequest(new \Nyholm\Psr7\Request('GET', 'https://idp.example.test/resources/client/service-format'));

        $scope = $this->methodsInScope();
        $this->assertCount(64, $scope);
        foreach ($scope as $method) {
            if (str_ends_with($method, '::__construct')) {
                MethodLogAssert::assertConstructorEntered($method);
            } else {
                MethodLogAssert::assertTraced($method);
            }
            $this->addToAssertionCount(1);
        }
    }

    /**
     * @return list<string>
     */
    private function methodsInScope(): array
    {
        $catalog = TracedMethodCatalog::forProject();
        $scoped = [];
        foreach ($catalog->all() as $method) {
            if (str_contains($method, '\\Controller\\') || str_contains($method, '\\Utilities\\Http\\')) {
                $scoped[] = $method;
            }
        }

        return $scoped;
    }

    private function request(string $method, string $path, array $query = [], array $body = []): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path);
        if ($query !== []) {
            $request = $request->withQueryParams($query);
        }
        if ($body !== []) {
            $request = $request->withParsedBody($body);
        }

        return $request;
    }
}
