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
use Amtgard\Denarius\Service\Access\PermissionService;
use Amtgard\Denarius\Service\Access\PrincipalSync;
use Amtgard\Denarius\Service\Enrollment\BankConnect;
use Amtgard\Denarius\Service\Enrollment\EnrollmentService;
use Amtgard\Denarius\Service\Kingdom\KingdomPageQuery;
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
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class TracedHttpMethodsTest extends AmtgardTestCase
{
    public function testEveryControllerAndHttpTraceSiteIsAsserted(): void
    {
        MethodLogAssert::reset();
        class_exists(ApplicationTest::class);

        $twig = new TwigHtmlRenderer(new Environment(new ArrayLoader([
            'home.twig' => 'home',
            'message.twig' => '{{ title }}',
            'kingdom.twig' => '{{ mode }} {{ rows|length }}',
            'admin.twig' => 'admin',
            'manage.twig' => 'manage',
        ])));
        $auth = new SessionAuthStore('test_session');
        $root = sys_get_temp_dir() . '/denarius-log-http-' . uniqid();
        mkdir($root);
        file_put_contents($root . '/VERSION', "1\n");

        $home = new HomeController($twig, $auth, $root);
        $home->home($this->request('GET', '/'), new Response());
        $home->version($this->request('GET', '/version'), new Response());

        $kingdoms = new MemoryKingdoms();
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
        $transactions->upsert(TransactionRecord::builder()->kingdomId(1)->tellerTransactionId('t')->tellerAccountId('acc')->postedOn('2026-09-02')->amountCents(250)->category('office')->build());
        $pages = new KingdomPageQuery($transactions, $accounts, MonthStatementBuilder::standard());
        $page = new KingdomPageController($kingdoms, $pages, KingdomAccess::standard(), $auth, $twig);
        $page->show($this->request('GET', '/missing'), new Response(), ['slug' => 'missing']);

        $_SESSION['test_session'] = (new AuthenticatedSession(
            new TokenSet('a'),
            new UserProfile(9, 'person@example.com', 'jwt', OrkProfile::fromArray(['kingdom_id' => 4, 'kingdom_name' => 'Golden Plains'])),
        ))->toSessionArray();
        $page->show($this->request('GET', '/golden-plains', ['month' => '2026-09']), new Response(), ['slug' => 'golden-plains']);
        $kingdoms->save(KingdomRecord::builder()->id($kingdom->getId())->orkKingdomId(4)->name('Golden Plains')->slug('golden-plains')->visibility('public')->displayMode('all')->enrollmentStatus('connected')->build());
        $page->show($this->request('GET', '/golden-plains', ['month' => '2026-09']), new Response(), ['slug' => 'golden-plains']);

        $permissions = new PermissionService(new FakePolicies([ClaimOrn::admin()]), new ArrayStore(), new DenariusAuthorizer(), BootstrapAdmins::fromEnv(null));
        $principals = new MemoryPrincipals();
        $principals->save(PrincipalRecord::builder()->idpUserId('9')->email('person@example.com')->build());
        $admin = new AdminController($auth, $permissions, $principals, $kingdoms, new FakePolicies([]), new MemoryGrants(), $twig, Strategies::admin());
        (new AdminController(new SessionAuthStore('empty'), $permissions, $principals, $kingdoms, new FakePolicies([]), new MemoryGrants(), $twig, Strategies::admin()))
            ->index($this->request('GET', '/admin'), new Response());
        $member = new PermissionService(new FakePolicies([]), new ArrayStore(), new DenariusAuthorizer(), BootstrapAdmins::fromEnv(null));
        (new AdminController($auth, $member, $principals, $kingdoms, new FakePolicies([]), new MemoryGrants(), $twig, Strategies::admin()))
            ->index($this->request('GET', '/admin'), new Response());
        $admin->index($this->request('GET', '/admin', ['email' => 'person']), new Response());
        $_SESSION['_csrf'] = 'token';
        $admin->grant($this->request('POST', '/admin/grant', [], ['csrf' => 'token', 'idp_user_id' => '9', 'action' => 'grant-manager', 'ork_kingdom_id' => '4', 'kingdom_name' => 'Golden Plains']), new Response());
        $admin->grant($this->request('POST', '/admin/grant', [], ['csrf' => 'nope']), new Response());

        $queue = new MemoryRefresh();
        $connects = new BankConnect(Strategies::providers(Strategies::teller()));
        $manager = new ManagerController(
            $auth,
            $permissions,
            $kingdoms,
            $accounts,
            new KingdomSettings($kingdoms),
            new EnrollmentService($kingdoms, new MemorySecrets(), $accounts, Strategies::providers(Strategies::teller()), new TokenCipher('k'), $queue, Strategies::months()),
            $queue,
            $twig,
            $connects,
        );
        (new ManagerController(new SessionAuthStore('empty'), $member, $kingdoms, $accounts, new KingdomSettings($kingdoms), new EnrollmentService($kingdoms, new MemorySecrets(), $accounts, Strategies::providers(Strategies::teller()), new TokenCipher('k'), $queue, Strategies::months()), $queue, $twig, $connects))
            ->show($this->request('GET', '/manage/golden-plains'), new Response(), ['slug' => 'golden-plains']);
        $manager->show($this->request('GET', '/manage/missing'), new Response(), ['slug' => 'missing']);
        $manager->show($this->request('GET', '/manage/golden-plains'), new Response(), ['slug' => 'golden-plains']);
        $manager->connect($this->request('POST', '/manage/golden-plains/connect', [], ['csrf' => 'token']), new Response(), ['slug' => 'golden-plains']);
        $manager->settings($this->request('POST', '/manage/golden-plains/settings', [], ['csrf' => 'token', 'visibility' => 'public', 'display_mode' => 'all']), new Response(), ['slug' => 'golden-plains']);
        $manager->enrollment($this->request('POST', '/manage/golden-plains/enrollment', [], ['csrf' => 'token', 'enrollment' => json_encode(['accessToken' => 'tok', 'id' => 'enr_9'])]), new Response(), ['slug' => 'golden-plains']);
        $manager->accounts($this->request('POST', '/manage/golden-plains/accounts', [], ['csrf' => 'token', 'published' => ['acc']]), new Response(), ['slug' => 'golden-plains']);
        $manager->refresh($this->request('POST', '/manage/golden-plains/refresh', [], ['csrf' => 'token']), new Response(), ['slug' => 'golden-plains']);
        $manager->settings($this->request('POST', '/x', [], ['csrf' => 'bad']), new Response(), ['slug' => 'golden-plains']);

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

        $sync = new SyncPrincipalMiddleware($auth, new PrincipalSync($principals));
        $sync->process($this->request('GET', '/'), new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        });

        $scope = $this->methodsInScope();
        $this->assertCount(38, $scope);
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
