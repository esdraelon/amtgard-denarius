<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Tests\Support\CategoryCatalogFixture;
use Amtgard\Denarius\Utilities\Auth\BootstrapAdmins;
use Amtgard\Denarius\Utilities\Auth\ClaimOrn;
use Amtgard\Denarius\Utilities\Auth\DenariusAuthorizer;
use Amtgard\Denarius\Utilities\Queue\Message\MessageQueue;
use Amtgard\Denarius\Controller\AdminController;
use Amtgard\Denarius\Controller\KingdomPageController;
use Amtgard\Denarius\Controller\ManagerController;
use Amtgard\Denarius\Domain\Access\KingdomAccess;
use Amtgard\Denarius\Domain\Statement\MonthStatementBuilder;
use Amtgard\Denarius\Domain\Statement\MonthWindow;
use Amtgard\Denarius\Utilities\Http\TwigHtmlRenderer;
use Amtgard\Denarius\Persistence\Record\AccountRecord;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Utilities\Security\TokenCipher;
use Amtgard\Denarius\Service\Enrollment\BankConnect;
use Amtgard\Denarius\Service\Ledger\DailySweep;
use Amtgard\Denarius\Service\Enrollment\EnrollmentService;
use Amtgard\Denarius\Tests\Support\KingdomPageQueryFactory;
use Amtgard\Denarius\Service\Kingdom\KingdomSettings;
use Amtgard\Denarius\Service\Access\PermissionService;
use Amtgard\Denarius\Service\Ledger\ProviderWebhookHandler;
use Amtgard\Denarius\Service\Ledger\TransactionSynchronizer;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Teller\Impl\CurlTellerApi;
use Amtgard\Denarius\Worker\LedgerWorker;
use Amtgard\IdpClient\OAuth\TokenSet;
use Amtgard\IdpClient\Resource\AuthenticatedSession;
use Amtgard\IdpClient\Resource\OrkProfile;
use Amtgard\IdpClient\Resource\UserProfile;
use Amtgard\IdpClient\Session\SessionAuthStore;
use Amtgard\PHPUnit\AmtgardTestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class CoverageTest extends AmtgardTestCase
{
    private static ?string $base = null;

    /** @var resource|null */
    private static $server = null;

    public static function httpBase(): ?string
    {
        return self::$base;
    }

    public static function setUpBeforeClass(): void
    {
        $root = sys_get_temp_dir() . '/denarius-http';
        if (!is_dir($root)) {
            mkdir($root);
        }
        file_put_contents($root . '/router.php', <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (str_contains((string) $path, '/fail')) {
    http_response_code(500);
    echo 'no';
    return;
}
if ($path === '/text') {
    echo 'hello';
    return;
}
if (str_contains((string) $path, 'transactions')) {
    echo '[{"id":"t"}]';
    return;
}
if ($path === '/accounts') {
    echo '[{"id":"a","name":"Checking"}]';
    return;
}
if ($path === '/ork') {
    echo json_encode(['Status' => ['Status' => 0], 'Kingdoms' => [['KingdomId' => 1, 'KingdomName' => 'Golden Plains']]]);
    return;
}
echo 'hello';
PHP);
        $port = 20000 + (getmypid() % 10000);
        $log = $root . '/server.log';
        $command = sprintf('php -S 127.0.0.1:%d %s', $port, escapeshellarg($root . '/router.php'));
        $process = proc_open($command, [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
        if (!is_resource($process)) {
            return;
        }
        self::$server = $process;
        self::$base = 'http://127.0.0.1:' . $port;
        $context = stream_context_create(['http' => ['timeout' => 1]]);
        for ($i = 0; $i < 20; $i++) {
            $probe = @file_get_contents(self::$base . '/text', false, $context);
            if ($probe === 'hello') {
                return;
            }
            usleep(50000);
        }
        self::$base = null;
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
    }

    public function testHttpClientsAdminEdgesAndWorker(): void
    {
        if (self::$base === null) {
            $this->fail('Local HTTP server did not start.');
        }
        $api = new CurlTellerApi(self::$base, '', '');
        $this->assertSame('a', $api->accounts('token')[0]['id']);
        $this->assertSame('t', $api->transactions('token', 'acc 1', 'from')[0]['id']);
        $this->assertSame('t', $api->transactions('token', 'acc', '')[0]['id']);
        $this->assertSame([], (new CurlTellerApi(self::$base . '/text', '', ''))->accounts('token'));
        $this->assertThrows(\RuntimeException::class, fn () => (new CurlTellerApi(self::$base . '/fail', '', ''))->accounts('token'));
        $cert = tempnam(sys_get_temp_dir(), 'cert');
        $key = tempnam(sys_get_temp_dir(), 'key');
        $this->assertSame('a', (new CurlTellerApi(self::$base, (string) $cert, (string) $key))->accounts('token')[0]['id']);

        $twig = new TwigHtmlRenderer(new Environment(new ArrayLoader([
            'admin.twig' => 'admin',
            'message.twig' => '{{ title }}',
            'kingdom.twig' => '{{ mode }} {{ rows|length }} {{ rows[0].kind|default("") }}',
            'manage.twig' => 'manage',
        ])));
        $_SESSION = [];
        $_SESSION['test_session'] = (new AuthenticatedSession(
            new TokenSet('a'),
            new UserProfile('9', 'person@example.com', 'jwt', OrkProfile::fromArray(['kingdom_id' => 4, 'kingdom_name' => 'Golden Plains'])),
        ))->toSessionArray();
        $auth = new SessionAuthStore('test_session');
        $permissions = new PermissionService(new FakePolicies([ClaimOrn::admin()]), new ArrayStore(), new DenariusAuthorizer(), BootstrapAdmins::fromEnv(null));
        $kingdoms = new MemoryKingdoms();
        $kingdom = $kingdoms->save(KingdomRecord::builder()->orkKingdomId(4)->name('Golden Plains')->slug('golden-plains')->visibility('public')->displayMode('summarized')->enrollmentStatus('connected')->build());
        $principals = new MemoryPrincipals();
        $grantTargets = Strategies::grantTargets($principals);
        $admin = new AdminController($auth, $permissions, $principals, $kingdoms, new FakePolicies([]), new MemoryGrants(), $twig, Strategies::admin(), Strategies::orkKingdoms($kingdoms, $principals), $grantTargets, Strategies::grantedRoles(new MemoryGrants(), $principals, $kingdoms), Strategies::principalSuggester($principals));
        $_SESSION['_csrf'] = 'token';
        $request = static fn (array $body) => (new ServerRequestFactory())->createServerRequest('POST', '/admin/grant')->withParsedBody($body);
        $this->assertSame(302, $admin->grant($request(['csrf' => 'token', 'idp_user_id' => '9', 'action' => 'grant-admin']), new Response())->getStatusCode());
        $this->assertSame(302, $admin->grant($request(['csrf' => 'token', 'idp_user_id' => '9', 'action' => 'revoke-admin']), new Response())->getStatusCode());
        $this->assertSame(302, $admin->grant($request(['csrf' => 'token', 'idp_user_id' => '9', 'action' => 'revoke-manager', 'ork_kingdom_id' => '4']), new Response())->getStatusCode());
        $this->assertSame(302, $admin->grant($request(['csrf' => 'token', 'idp_user_id' => '9', 'action' => 'unknown']), new Response())->getStatusCode());
        $member = new PermissionService(new FakePolicies([]), new ArrayStore(), new DenariusAuthorizer(), BootstrapAdmins::fromEnv(null));
        $forbidden = (new AdminController($auth, $member, $principals, $kingdoms, new FakePolicies([]), new MemoryGrants(), $twig, Strategies::admin(), Strategies::orkKingdoms($kingdoms, $principals), $grantTargets, Strategies::grantedRoles(new MemoryGrants(), $principals, $kingdoms), Strategies::principalSuggester($principals)))
            ->index((new ServerRequestFactory())->createServerRequest('GET', '/admin'), new Response());
        $this->assertSame(403, $forbidden->getStatusCode());

        $accounts = new MemoryAccounts();
        $accounts->save(AccountRecord::builder()->kingdomId(1)->tellerAccountId('acc')->name('Checking')->published(true)->build());
        $accounts->save(AccountRecord::builder()->kingdomId(1)->tellerAccountId('hidden')->name('Savings')->published(false)->build());
        $transactions = new MemoryTransactions();
        $transactions->upsert(TransactionRecord::builder()->kingdomId(1)->tellerTransactionId('t')->tellerAccountId('acc')->postedOn('2026-09-02')->amountCents(250)->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('office'))->publishedAt('2026-09-03T00:00:00+00:00')->build());
        $transactions->upsert(TransactionRecord::builder()->kingdomId(1)->tellerTransactionId('h')->tellerAccountId('hidden')->postedOn('2026-09-02')->amountCents(10)->categoryId(\Amtgard\Denarius\Tests\Support\CategoryCatalogFixture::id('fuel'))->build());
        $page = new KingdomPageController($kingdoms, KingdomPageQueryFactory::publicRead($transactions, $accounts), KingdomAccess::standard(), $auth, $twig);
        $shown = $page->show((new ServerRequestFactory())->createServerRequest('GET', '/golden-plains')->withQueryParams(['month' => '2026-09']), new Response(), 'golden-plains');
        $this->assertStringContainsString('summarized', (string) $shown->getBody());
        $this->assertStringContainsString('total', (string) $shown->getBody());

        $queue = new MemoryRefresh();
        $connects = new BankConnect(Strategies::providers(Strategies::teller()));
        $manager = new ManagerController($auth, $permissions, $kingdoms, $accounts, Strategies::kingdomSettings($kingdoms), new EnrollmentService($kingdoms, new MemorySecrets(), $accounts, Strategies::providers(Strategies::teller()), new TokenCipher('k'), $queue, Strategies::months(), Strategies::bankReset()), $queue, $twig, $connects, new \Amtgard\Denarius\Service\Enrollment\SimpleFinConnectSession(), Strategies::reviewQueue($transactions, $accounts), Strategies::reviewService($transactions, $accounts), Strategies::kingdomScopedCategorySearch(), Strategies::kingdomPatternService($kingdoms, $transactions), Strategies::patternWizard($kingdoms, $transactions, $accounts), Strategies::patternPrefill(), Strategies::ledgerSyncFeedback(), Strategies::patternAutomaticReview($kingdoms, $transactions, $accounts));
        $this->assertSame(404, $manager->show((new ServerRequestFactory())->createServerRequest('GET', '/manage/missing'), new Response(), 'missing')->getStatusCode());
        $guest = new ManagerController(new SessionAuthStore('empty'), $member, $kingdoms, $accounts, Strategies::kingdomSettings($kingdoms), new EnrollmentService($kingdoms, new MemorySecrets(), $accounts, Strategies::providers(Strategies::teller()), new TokenCipher('k'), $queue, Strategies::months(), Strategies::bankReset()), $queue, $twig, $connects, new \Amtgard\Denarius\Service\Enrollment\SimpleFinConnectSession(), Strategies::reviewQueue($transactions, $accounts), Strategies::reviewService($transactions, $accounts), Strategies::kingdomScopedCategorySearch(), Strategies::kingdomPatternService($kingdoms, $transactions), Strategies::patternWizard($kingdoms, $transactions, $accounts), Strategies::patternPrefill(), Strategies::ledgerSyncFeedback(), Strategies::patternAutomaticReview($kingdoms, $transactions, $accounts));
        $this->assertSame(302, $guest->show((new ServerRequestFactory())->createServerRequest('GET', '/manage/golden-plains'), new Response(), 'golden-plains')->getStatusCode());
        $this->assertSame(400, $manager->enrollment((new ServerRequestFactory())->createServerRequest('POST', '/e')->withParsedBody(['csrf' => 'token', 'enrollment' => '{']), new Response(), 'golden-plains')->getStatusCode());

        $this->assertSame(1, (new DailySweep($kingdoms, $queue))->enqueueConnected());

        $messages = new class implements MessageQueue {
            public array $published = [];
            public ?\Closure $failure = null;
            public function publish(string $queue, string $key, string $message): void
            {
                $this->published[] = $message;
            }
            public function redrive(string $queue): void
            {
            }
            public function subscribe(string $queue, callable $callback, ?callable $failure): void
            {
                $this->failure = $failure;
            }
            public function callConsumers(string $queue): int
            {
                if ($this->failure !== null) {
                    ($this->failure)(new \Exception('failed'), 'ledger:4', '{"type":"ledger"}');
                    $this->failure = null;
                }
                return 0;
            }
        };
        $sync = Strategies::synchronizer($kingdoms, $accounts, new MemorySecrets(), $transactions, Strategies::providers(Strategies::teller()), new TokenCipher('k'), new \DateTimeImmutable('2026-09-01'), Strategies::months());
        $worker = new LedgerWorker($messages, Strategies::jobs($sync), 1);
        $this->assertSame(0, $worker->run(1));
        $this->assertCount(1, $messages->published);
        $worker->handle('{"type":"other"}');
        $worker->handle('not-json');

        $handler = new ProviderWebhookHandler(Strategies::providers(Strategies::teller()), $kingdoms, Strategies::events($queue, new EnrollmentService($kingdoms, new MemorySecrets(), $accounts, Strategies::providers(Strategies::teller()), new TokenCipher('k'), $queue, Strategies::months(), Strategies::bankReset())));
        $body = json_encode(['type' => 'enrollment.updated', 'enrollment_id' => 'enr_1']);
        $now = 1_700_000_000;
        $signature = 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . $body, 'whsec');
        $kingdoms->save(KingdomRecord::builder()->id($kingdom->getId())->orkKingdomId(4)->name('Golden Plains')->slug('golden-plains')->enrollmentId('enr_1')->provider('teller')->enrollmentStatus('connected')->build());
        $this->assertTrue($handler->handle('teller', (string) $body, $signature, $now));
        $this->assertSame('2026-09', MonthWindow::current(new \DateTimeImmutable('2026-09-15'))->key());
    }
}
