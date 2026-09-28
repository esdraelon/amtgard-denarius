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
use Amtgard\Denarius\Service\EnrollmentService;
use Amtgard\Denarius\Service\KingdomPageQuery;
use Amtgard\Denarius\Service\KingdomSettings;
use Amtgard\Denarius\Service\PermissionService;
use Amtgard\Denarius\Service\PrincipalSync;
use Amtgard\Denarius\Service\ProviderWebhookHandler;
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
        $twig = new TwigHtmlRenderer(new Environment(new ArrayLoader([
            'home.twig' => 'home {{ version }} {{ authenticated ? "yes" : "no" }}',
            'message.twig' => '{{ title }} {{ message }}',
            'kingdom.twig' => '{{ kingdom.name }} {{ mode }} {{ month }} {{ rows|length }}',
            'admin.twig' => 'admin {{ principals|length }}',
            'manage.twig' => 'manage {{ kingdom.slug }}',
        ])));
        $auth = new SessionAuthStore('test_session');
        $root = sys_get_temp_dir() . '/denarius-ctrl-' . uniqid();
        mkdir($root);
        file_put_contents($root . '/VERSION', "1\n");
        $home = new HomeController($twig, $auth, $root);
        $response = $home->home($this->request('GET', '/'), new Response());
        $this->assertStringContainsString('home 1 no', (string) $response->getBody());
        $version = $home->version($this->request('GET', '/version'), new Response());
        $this->assertStringContainsString('"version":"1"', (string) $version->getBody());

        $kingdoms = new MemoryKingdoms();
        $kingdom = $kingdoms->save(KingdomRecord::builder()->orkKingdomId(4)->name('Golden Plains')->slug('golden-plains')->visibility('public')->displayMode('all')->enrollmentStatus('disconnected')->lastSyncedAt('2026-09-01')->build());
        $accounts = new MemoryAccounts();
        $accounts->save(\Amtgard\Denarius\Persistence\Record\AccountRecord::builder()->kingdomId(1)->tellerAccountId('acc')->name('Checking')->type('depository')->published(true)->build());
        $transactions = new MemoryTransactions();
        $transactions->upsert(\Amtgard\Denarius\Persistence\Record\TransactionRecord::builder()->kingdomId(1)->tellerTransactionId('t')->tellerAccountId('acc')->postedOn('2026-09-02')->amountCents(100)->category('office')->description('paper')->counterparty('Shop')->status('posted')->build());
        $pages = new KingdomPageQuery($transactions, $accounts, MonthStatementBuilder::standard());
        $page = new KingdomPageController($kingdoms, $pages, KingdomAccess::standard(), $auth, $twig);
        $missing = $page->show($this->request('GET', '/missing'), new Response(), ['slug' => 'missing']);
        $this->assertSame(404, $missing->getStatusCode());
        $shown = $page->show($this->request('GET', '/golden-plains', ['month' => '2026-09']), new Response(), ['slug' => 'golden-plains']);
        $this->assertStringContainsString('Golden Plains', (string) $shown->getBody());

        $kingdoms->save(KingdomRecord::builder()->id($kingdom->getId())->orkKingdomId(4)->name('Golden Plains')->slug('golden-plains')->visibility('registered')->displayMode('redacted')->enrollmentStatus('none')->build());
        $login = $page->show($this->request('GET', '/golden-plains'), new Response(), ['slug' => 'golden-plains']);
        $this->assertSame(302, $login->getStatusCode());

        $_SESSION['test_session'] = (new AuthenticatedSession(
            new TokenSet('a'),
            new UserProfile(9, 'person@example.com', 'jwt', OrkProfile::fromArray(['kingdom_id' => 8, 'kingdom_name' => 'Other'])),
        ))->toSessionArray();
        $denied = $page->show($this->request('GET', '/golden-plains'), new Response(), ['slug' => 'golden-plains']);
        $kingdoms->save(KingdomRecord::builder()->id($kingdom->getId())->orkKingdomId(4)->name('Golden Plains')->slug('golden-plains')->visibility('kingdom_only')->displayMode('summarized')->enrollmentStatus('none')->build());
        $denied = $page->show($this->request('GET', '/golden-plains', ['month' => '2026-09']), new Response(), ['slug' => 'golden-plains']);
        $this->assertSame(403, $denied->getStatusCode());

        $permissions = new PermissionService(new FakePolicies([\Amtgard\Denarius\Utilities\Auth\ClaimOrn::admin()]), new ArrayStore(), new DenariusAuthorizer(), BootstrapAdmins::fromEnv(null));
        $principals = new MemoryPrincipals();
        $principals->save(PrincipalRecord::builder()->idpUserId('9')->email('person@example.com')->build());
        $admin = new AdminController($auth, $permissions, $principals, $kingdoms, new FakePolicies([]), new MemoryGrants(), $twig, Strategies::admin());
        $anon = new SessionAuthStore('empty');
        $guest = (new AdminController($anon, $permissions, $principals, $kingdoms, new FakePolicies([]), new MemoryGrants(), $twig, Strategies::admin()))
            ->index($this->request('GET', '/admin'), new Response());
        $this->assertSame(302, $guest->getStatusCode());
        $index = $admin->index($this->request('GET', '/admin', ['email' => 'person']), new Response());
        $this->assertStringContainsString('admin 1', (string) $index->getBody());
        $_SESSION['_csrf'] = 'token';
        $granted = $admin->grant($this->request('POST', '/admin/grant', [], ['csrf' => 'token', 'idp_user_id' => '9', 'action' => 'grant-manager', 'ork_kingdom_id' => '4', 'kingdom_name' => 'Golden Plains']), new Response());
        $this->assertSame(302, $granted->getStatusCode());
        $bad = $admin->grant($this->request('POST', '/admin/grant', [], ['csrf' => 'nope']), new Response());
        $this->assertSame(403, $bad->getStatusCode());

        $queue = new MemoryRefresh();
        $manager = new ManagerController(
            $auth,
            $permissions,
            $kingdoms,
            $accounts,
            new KingdomSettings($kingdoms),
            new EnrollmentService($kingdoms, new MemorySecrets(), $accounts, Strategies::providers(Strategies::teller()), new TokenCipher('k'), $queue, Strategies::months()),
            $queue,
            $twig,
            new \Amtgard\Denarius\Service\BankConnect(Strategies::providers(Strategies::teller())),
        );
        $manage = $manager->show($this->request('GET', '/manage/golden-plains'), new Response(), ['slug' => 'golden-plains']);
        $this->assertStringContainsString('manage golden-plains', (string) $manage->getBody());
        $saved = $manager->settings($this->request('POST', '/manage/golden-plains/settings', [], ['csrf' => 'token', 'visibility' => 'public', 'display_mode' => 'all']), new Response(), ['slug' => 'golden-plains']);
        $this->assertSame(302, $saved->getStatusCode());
        $enrolled = $manager->enrollment($this->request('POST', '/manage/golden-plains/enrollment', [], ['csrf' => 'token', 'enrollment' => json_encode(['accessToken' => 'tok', 'id' => 'enr_9'])]), new Response(), ['slug' => 'golden-plains']);
        $this->assertSame(302, $enrolled->getStatusCode());
        $accountsSaved = $manager->accounts($this->request('POST', '/manage/golden-plains/accounts', [], ['csrf' => 'token', 'published' => ['acc_1']]), new Response(), ['slug' => 'golden-plains']);
        $this->assertSame(302, $accountsSaved->getStatusCode());
        $refreshed = $manager->refresh($this->request('POST', '/manage/golden-plains/refresh', [], ['csrf' => 'token']), new Response(), ['slug' => 'golden-plains']);
        $this->assertSame(302, $refreshed->getStatusCode());
        $this->assertSame(403, $manager->settings($this->request('POST', '/x', [], ['csrf' => 'bad']), new Response(), ['slug' => 'golden-plains'])->getStatusCode());

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
