<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Utilities\Auth\BootstrapAdmins;
use Amtgard\Denarius\Utilities\Auth\DenariusAuthorizer;
use Amtgard\Denarius\Controller\AdminController;
use Amtgard\Denarius\Controller\HomeController;
use Amtgard\Denarius\Controller\KingdomPageController;
use Amtgard\Denarius\Controller\ManagerController;
use Amtgard\Denarius\Controller\WebhookController;
use Amtgard\Denarius\Domain\Access\KingdomAccess;
use Amtgard\Denarius\Domain\Statement\MonthStatementBuilder;
use Amtgard\Denarius\Utilities\Http\SyncPrincipalMiddleware;
use Amtgard\Denarius\Utilities\Http\TwigHtmlRenderer;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Persistence\Record\PrincipalRecord;
use Amtgard\Denarius\Utilities\Security\TokenCipher;
use Amtgard\Denarius\Service\Enrollment\EnrollmentService;
use Amtgard\Denarius\Tests\Support\KingdomPageQueryFactory;
use Amtgard\Denarius\Service\Kingdom\KingdomSettings;
use Amtgard\Denarius\Service\Access\AccountNavBuilder;
use Amtgard\Denarius\Service\Access\PermissionService;
use Amtgard\Denarius\Service\Access\PrincipalSync;
use Amtgard\Denarius\Service\Ledger\ProviderWebhookHandler;
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

final class ControllerTest extends AmtgardTestCase
{
    public function testHomeVersionKingdomAdminManagerAndWebhook(): void
    {
        unset($_SESSION['test_session'], $_SESSION['_csrf']);
        $twig = new TwigHtmlRenderer(new Environment(new ArrayLoader([
            'home.twig' => 'home {{ version }} {{ authenticated ? "yes" : "no" }} actions {{ accountActions|length }}',
            'message.twig' => '{{ title }} {{ message }}',
            'kingdom.twig' => '{{ kingdom.name }} {{ mode }} {{ month }} {{ rows|length }}',
            'admin.twig' => 'admin {{ principals|length }} grants {{ grantedPermissions|length }}',
            'manage.twig' => 'manage {{ kingdom.slug }}',
            'privacy-policy.twig' => 'Privacy Policy {{ contactEmail }}',
        ])));
        $auth = new SessionAuthStore('test_session');
        $root = sys_get_temp_dir() . '/denarius-ctrl-' . uniqid();
        mkdir($root);
        file_put_contents($root . '/VERSION', "1\n");
        $permissions = new PermissionService(new FakePolicies([]), new ArrayStore(), new DenariusAuthorizer(), BootstrapAdmins::fromEnv(null));
        $homeKingdoms = new MemoryKingdoms();
        $homePrincipals = new MemoryPrincipals();
        $home = new HomeController(
            $twig,
            $auth,
            new AccountNavBuilder($permissions, Strategies::managedKingdomResolver($homeKingdoms, $homePrincipals)),
            $homeKingdoms,
            KingdomAccess::standard(),
            Strategies::orkKingdoms($homeKingdoms, $homePrincipals),
            $root,
        );
        $response = $home->home($this->request('GET', '/'), new Response());
        $this->assertStringContainsString('home 1 no', (string) $response->getBody());
        $version = $home->version($this->request('GET', '/version'), new Response());
        $this->assertStringContainsString('"version":"1"', (string) $version->getBody());
        $privacy = $home->privacyPolicy($this->request('GET', '/privacy-policy'), new Response());
        $this->assertSame(200, $privacy->getStatusCode());
        $this->assertStringContainsString('Privacy Policy', (string) $privacy->getBody());
        $this->assertStringContainsString('privacy@amtgard.com', (string) $privacy->getBody());

        $kingdoms = new MemoryKingdoms();
        $kingdom = $kingdoms->save(KingdomRecord::builder()->orkKingdomId(4)->name('Golden Plains')->slug('golden-plains')->visibility('public')->displayMode('all')->enrollmentStatus('disconnected')->lastSyncedAt('2026-09-01')->build());
        $accounts = new MemoryAccounts();
        $accounts->save(\Amtgard\Denarius\Persistence\Record\AccountRecord::builder()->kingdomId(1)->tellerAccountId('acc')->name('Checking')->type('depository')->published(true)->build());
        $transactions = new MemoryTransactions();
        $transactions->upsert(\Amtgard\Denarius\Persistence\Record\TransactionRecord::builder()->kingdomId(1)->tellerTransactionId('t')->tellerAccountId('acc')->postedOn('2026-09-02')->amountCents(100)->category('office')->description('paper')->counterparty('Shop')->status('posted')->build());
        $pages = KingdomPageQueryFactory::publicRead($transactions, $accounts);
        $page = new KingdomPageController($kingdoms, $pages, KingdomAccess::standard(), $auth, $twig);
        $missing = $page->show($this->request('GET', '/missing'), new Response(), 'missing');
        $this->assertSame(404, $missing->getStatusCode());
        $shown = $page->show($this->request('GET', '/golden-plains', ['month' => '2026-09']), new Response(), 'golden-plains');
        $this->assertStringContainsString('Golden Plains', (string) $shown->getBody());

        $kingdoms->save(KingdomRecord::builder()->id($kingdom->getId())->orkKingdomId(4)->name('Golden Plains')->slug('golden-plains')->visibility('registered')->displayMode('redacted')->enrollmentStatus('none')->build());
        $login = $page->show($this->request('GET', '/golden-plains'), new Response(), 'golden-plains');
        $this->assertSame(302, $login->getStatusCode());

        $_SESSION['test_session'] = (new AuthenticatedSession(
            new TokenSet('a'),
            new UserProfile('9', 'person@example.com', 'jwt', OrkProfile::fromArray(['kingdom_id' => 8, 'kingdom_name' => 'Other'])),
        ))->toSessionArray();
        $denied = $page->show($this->request('GET', '/golden-plains'), new Response(), 'golden-plains');
        $kingdoms->save(KingdomRecord::builder()->id($kingdom->getId())->orkKingdomId(4)->name('Golden Plains')->slug('golden-plains')->visibility('kingdom_only')->displayMode('summarized')->enrollmentStatus('none')->build());
        $denied = $page->show($this->request('GET', '/golden-plains', ['month' => '2026-09']), new Response(), 'golden-plains');
        $this->assertSame(403, $denied->getStatusCode());

        $permissions = new PermissionService(new FakePolicies([\Amtgard\Denarius\Utilities\Auth\ClaimOrn::admin()]), new ArrayStore(), new DenariusAuthorizer(), BootstrapAdmins::fromEnv(null));
        $principals = new MemoryPrincipals();
        $principals->save(PrincipalRecord::builder()->idpUserId('9')->email('person@example.com')->build());
        $orkKingdoms = Strategies::orkKingdoms($kingdoms, $principals);
        $grantTargets = Strategies::grantTargets($principals);
        $grants = new MemoryGrants();
        $grantedRoles = Strategies::grantedRoles($grants, $principals, $kingdoms);
        $admin = new AdminController($auth, $permissions, $principals, $kingdoms, new FakePolicies([]), $grants, $twig, Strategies::admin(), $orkKingdoms, $grantTargets, $grantedRoles);
        $anon = new SessionAuthStore('empty');
        $guest = (new AdminController($anon, $permissions, $principals, $kingdoms, new FakePolicies([]), new MemoryGrants(), $twig, Strategies::admin(), $orkKingdoms, $grantTargets, Strategies::grantedRoles(new MemoryGrants(), $principals, $kingdoms)))
            ->index($this->request('GET', '/admin'), new Response());
        $this->assertSame(302, $guest->getStatusCode());
        $index = $admin->index($this->request('GET', '/admin', ['email' => 'person']), new Response());
        $this->assertStringContainsString('admin 1', (string) $index->getBody());
        $_SESSION['_csrf'] = 'token';
        $granted = $admin->grant($this->request('POST', '/admin/grant', [], ['csrf' => 'token', 'idp_user_id' => '9', 'action' => 'grant-manager', 'ork_kingdom_id' => '4', 'kingdom_name' => 'Golden Plains']), new Response());
        $this->assertSame(302, $granted->getStatusCode());
        $grants->append(\Amtgard\Denarius\Persistence\Record\RoleGrantRecord::builder()
            ->actorIdpUserId('3')
            ->targetIdpUserId('9')
            ->action('grant')
            ->resource('Denarius/ManageKingdom')
            ->orkKingdomId(4)
            ->createdAt('2026-09-30T12:00:00+00:00')
            ->build());
        $table = $admin->index($this->request('GET', '/admin', ['perm_email' => 'person', 'perm_kingdom' => 'Golden']), new Response());
        $this->assertStringContainsString('grants 1', (string) $table->getBody());
        $iamDenied = new ClientIamDeniedPolicies();
        $adminIamDenied = new AdminController($auth, $permissions, $principals, $kingdoms, $iamDenied, $grants, $twig, Strategies::admin(), $orkKingdoms, $grantTargets, $grantedRoles);
        $deniedGrant = $adminIamDenied->grant($this->request('POST', '/admin/grant', [], ['csrf' => 'token', 'idp_user_id' => '9', 'action' => 'grant-manager', 'ork_kingdom_id' => '4', 'kingdom_name' => 'Golden Plains']), new Response());
        $this->assertSame(503, $deniedGrant->getStatusCode());
        $this->assertStringContainsString('IDP_CLIENT_ID', (string) $deniedGrant->getBody());
        $bad = $admin->grant($this->request('POST', '/admin/grant', [], ['csrf' => 'nope']), new Response());
        $this->assertSame(403, $bad->getStatusCode());

        $queue = new MemoryRefresh();
        $transactions = new MemoryTransactions();
        $manager = new ManagerController(
            $auth,
            $permissions,
            $kingdoms,
            $accounts,
            Strategies::kingdomSettings($kingdoms),
            new EnrollmentService($kingdoms, new MemorySecrets(), $accounts, Strategies::providers(Strategies::teller()), new TokenCipher('k'), $queue, Strategies::months()),
            $queue,
            $twig,
            new \Amtgard\Denarius\Service\Enrollment\BankConnect(Strategies::providers(Strategies::teller())),
            new \Amtgard\Denarius\Service\Enrollment\SimpleFinConnectSession(),
            Strategies::reviewQueue($transactions, $accounts),
            Strategies::reviewService($transactions, $accounts),
            Strategies::categorySearch(),
            Strategies::kingdomPatternService($kingdoms, $transactions),
            Strategies::patternPrefill(),
            Strategies::ledgerSyncFeedback(),
        );
        $manage = $manager->show($this->request('GET', '/manage/golden-plains'), new Response(), 'golden-plains');
        $this->assertStringContainsString('manage golden-plains', (string) $manage->getBody());
        $saved = $manager->settings($this->request('POST', '/manage/golden-plains/settings', [], ['csrf' => 'token', 'visibility' => 'public', 'display_mode' => 'all']), new Response(), 'golden-plains');
        $this->assertSame(302, $saved->getStatusCode());
        $enrolled = $manager->enrollment($this->request('POST', '/manage/golden-plains/enrollment', [], ['csrf' => 'token', 'enrollment' => json_encode(['accessToken' => 'tok', 'id' => 'enr_9'])]), new Response(), 'golden-plains');
        $this->assertSame(302, $enrolled->getStatusCode());
        $accountsSaved = $manager->accounts($this->request('POST', '/manage/golden-plains/accounts', [], ['csrf' => 'token', 'published' => ['acc_1']]), new Response(), 'golden-plains');
        $this->assertSame(302, $accountsSaved->getStatusCode());
        $refreshed = $manager->refresh($this->request('POST', '/manage/golden-plains/refresh', [], ['csrf' => 'token']), new Response(), 'golden-plains');
        $this->assertSame(302, $refreshed->getStatusCode());
        $this->assertSame(403, $manager->settings($this->request('POST', '/x', [], ['csrf' => 'bad']), new Response(), 'golden-plains')->getStatusCode());

        $webhook = new WebhookController(new ProviderWebhookHandler(Strategies::providers(Strategies::teller()), $kingdoms, Strategies::events($queue, new EnrollmentService($kingdoms, new MemorySecrets(), $accounts, Strategies::providers(Strategies::teller()), new TokenCipher('k'), $queue, Strategies::months()))));
        $rejected = $webhook->teller($this->request('POST', '/webhooks/teller'), new Response());
        $this->assertSame(400, $rejected->getStatusCode());

        $sync = new SyncPrincipalMiddleware($auth, new PrincipalSync($principals));
        $handled = $sync->process($this->request('GET', '/'), new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        });
        $this->assertSame(200, $handled->getStatusCode());
        $this->assertNotNull($principals->findByIdpUserId('9'));
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
