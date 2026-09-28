<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Auth\BootstrapAdmins;
use Amtgard\Denarius\Auth\ClaimOrn;
use Amtgard\Denarius\Auth\DenariusAuthorizer;
use Amtgard\Denarius\Contract\MessageQueue;
use Amtgard\Denarius\Controller\AdminController;
use Amtgard\Denarius\Controller\KingdomPageController;
use Amtgard\Denarius\Controller\ManagerController;
use Amtgard\Denarius\Domain\KingdomAccess;
use Amtgard\Denarius\Domain\MonthStatementBuilder;
use Amtgard\Denarius\Domain\MonthWindow;
use Amtgard\Denarius\Http\TwigHtmlRenderer;
use Amtgard\Denarius\Ork\HttpOrkKingdomClient;
use Amtgard\Denarius\Ork\OrkKingdomParser;
use Amtgard\Denarius\Record\AccountRecord;
use Amtgard\Denarius\Record\KingdomRecord;
use Amtgard\Denarius\Record\TransactionRecord;
use Amtgard\Denarius\Security\TokenCipher;
use Amtgard\Denarius\Service\CachedKingdomDirectory;
use Amtgard\Denarius\Service\DailySweep;
use Amtgard\Denarius\Service\EnrollmentService;
use Amtgard\Denarius\Service\KingdomPageQuery;
use Amtgard\Denarius\Service\KingdomSettings;
use Amtgard\Denarius\Service\PermissionService;
use Amtgard\Denarius\Service\TellerWebhookHandler;
use Amtgard\Denarius\Service\TransactionSynchronizer;
use Amtgard\Denarius\Teller\CurlTellerApi;
use Amtgard\Denarius\Teller\TellerWebhookVerifier;
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

        $parser = new OrkKingdomParser();
        $ork = new HttpOrkKingdomClient(self::$base . '/ork?x=1', 'denarius-test', 'https://denarius.amtgard.com', $parser);
        $this->assertSame('Golden Plains', $ork->listKingdoms()[0]->name);
        $this->assertThrows(\RuntimeException::class, fn () => (new HttpOrkKingdomClient(self::$base, '', 'referer', $parser))->listKingdoms());
        $this->assertThrows(\RuntimeException::class, fn () => (new HttpOrkKingdomClient(self::$base . '/fail', 'agent', 'referer', $parser))->listKingdoms());
        $this->assertSame([], (new HttpOrkKingdomClient(self::$base . '/text', 'agent', 'referer', $parser))->listKingdoms());

        $twig = new TwigHtmlRenderer(new Environment(new ArrayLoader([
            'admin.twig' => 'admin',
            'message.twig' => '{{ title }}',
            'kingdom.twig' => '{{ mode }} {{ rows|length }} {{ rows[0].kind|default("") }}',
            'manage.twig' => 'manage',
        ])));
        $_SESSION = [];
        $_SESSION['test_session'] = (new AuthenticatedSession(
            new TokenSet('a'),
            new UserProfile(9, 'person@example.com', 'jwt', OrkProfile::fromArray(['kingdom_id' => 4, 'kingdom_name' => 'Golden Plains'])),
        ))->toSessionArray();
        $auth = new SessionAuthStore('test_session');
        $permissions = new PermissionService(new FakePolicies([ClaimOrn::admin()]), new ArrayStore(), new DenariusAuthorizer(), BootstrapAdmins::fromEnv(null));
        $directory = new CachedKingdomDirectory(new class implements \Amtgard\Denarius\Contract\OrkKingdomClient {
            public function listKingdoms(): array
            {
                return [];
            }
        }, new ArrayStore(), new MemoryRefresh());
        $kingdoms = new MemoryKingdoms();
        $kingdom = $kingdoms->save(KingdomRecord::builder()->orkKingdomId(4)->name('Golden Plains')->slug('golden-plains')->visibility('public')->displayMode('summarized')->enrollmentStatus('connected')->build());
        $admin = new AdminController($auth, $permissions, $directory, new MemoryPrincipals(), $kingdoms, new FakePolicies([]), new MemoryGrants(), $twig, Strategies::admin($directory));
        $_SESSION['_csrf'] = 'token';
        $request = static fn (array $body) => (new ServerRequestFactory())->createServerRequest('POST', '/admin/grant')->withParsedBody($body);
        $this->assertSame(302, $admin->grant($request(['csrf' => 'token', 'idp_user_id' => '9', 'action' => 'grant-admin']), new Response())->getStatusCode());
        $this->assertSame(302, $admin->grant($request(['csrf' => 'token', 'idp_user_id' => '9', 'action' => 'revoke-admin']), new Response())->getStatusCode());
        $this->assertSame(302, $admin->grant($request(['csrf' => 'token', 'idp_user_id' => '9', 'action' => 'revoke-manager', 'ork_kingdom_id' => '4']), new Response())->getStatusCode());
        $this->assertSame(302, $admin->grant($request(['csrf' => 'token', 'idp_user_id' => '9', 'action' => 'unknown']), new Response())->getStatusCode());
        $member = new PermissionService(new FakePolicies([]), new ArrayStore(), new DenariusAuthorizer(), BootstrapAdmins::fromEnv(null));
        $forbidden = (new AdminController($auth, $member, $directory, new MemoryPrincipals(), $kingdoms, new FakePolicies([]), new MemoryGrants(), $twig, Strategies::admin($directory)))
            ->index((new ServerRequestFactory())->createServerRequest('GET', '/admin'), new Response());
        $this->assertSame(403, $forbidden->getStatusCode());

        $accounts = new MemoryAccounts();
        $accounts->save(AccountRecord::builder()->kingdomId(1)->tellerAccountId('acc')->name('Checking')->published(true)->build());
        $accounts->save(AccountRecord::builder()->kingdomId(1)->tellerAccountId('hidden')->name('Savings')->published(false)->build());
        $transactions = new MemoryTransactions();
        $transactions->upsert(TransactionRecord::builder()->kingdomId(1)->tellerTransactionId('t')->tellerAccountId('acc')->postedOn('2026-09-02')->amountCents(250)->category('office')->build());
        $transactions->upsert(TransactionRecord::builder()->kingdomId(1)->tellerTransactionId('h')->tellerAccountId('hidden')->postedOn('2026-09-02')->amountCents(10)->category('fuel')->build());
        $page = new KingdomPageController($kingdoms, new KingdomPageQuery($transactions, $accounts, MonthStatementBuilder::standard()), KingdomAccess::standard(), $auth, $twig);
        $shown = $page->show((new ServerRequestFactory())->createServerRequest('GET', '/golden-plains')->withQueryParams(['month' => '2026-09']), new Response(), ['slug' => 'golden-plains']);
        $this->assertStringContainsString('summarized', (string) $shown->getBody());
        $this->assertStringContainsString('total', (string) $shown->getBody());

        $queue = new MemoryRefresh();
        $manager = new ManagerController($auth, $permissions, $kingdoms, $accounts, new KingdomSettings($kingdoms), new EnrollmentService($kingdoms, new MemorySecrets(), $accounts, new FakeTeller(), new TokenCipher('k'), $queue), $queue, $twig, 'app', 'sandbox');
        $this->assertSame(404, $manager->show((new ServerRequestFactory())->createServerRequest('GET', '/manage/missing'), new Response(), ['slug' => 'missing'])->getStatusCode());
        $guest = new ManagerController(new SessionAuthStore('empty'), $member, $kingdoms, $accounts, new KingdomSettings($kingdoms), new EnrollmentService($kingdoms, new MemorySecrets(), $accounts, new FakeTeller(), new TokenCipher('k'), $queue), $queue, $twig, 'app', 'sandbox');
        $this->assertSame(302, $guest->show((new ServerRequestFactory())->createServerRequest('GET', '/manage/golden-plains'), new Response(), ['slug' => 'golden-plains'])->getStatusCode());
        $this->assertSame(400, $manager->enrollment((new ServerRequestFactory())->createServerRequest('POST', '/e')->withParsedBody(['csrf' => 'token', 'enrollment' => '{']), new Response(), ['slug' => 'golden-plains'])->getStatusCode());

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
        $sync = new TransactionSynchronizer($kingdoms, $accounts, new MemorySecrets(), $transactions, new FakeTeller(), new TokenCipher('k'), new \DateTimeImmutable('2026-09-01'));
        $worker = new LedgerWorker($messages, Strategies::jobs($directory, $sync), 1);
        $this->assertSame(0, $worker->run(1));
        $this->assertCount(1, $messages->published);
        $worker->handle('{"type":"other"}');
        $worker->handle('not-json');

        $handler = new TellerWebhookHandler(new TellerWebhookVerifier('whsec'), $kingdoms, Strategies::events($queue, new EnrollmentService($kingdoms, new MemorySecrets(), $accounts, new FakeTeller(), new TokenCipher('k'), $queue)));
        $body = json_encode(['type' => 'enrollment.updated', 'enrollment_id' => 'enr_1']);
        $now = 1_700_000_000;
        $signature = 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . $body, 'whsec');
        $kingdoms->save(KingdomRecord::builder()->id($kingdom->getId())->orkKingdomId(4)->name('Golden Plains')->slug('golden-plains')->enrollmentId('enr_1')->enrollmentStatus('connected')->build());
        $this->assertTrue($handler->handle((string) $body, $signature, $now));
        $this->assertSame('2026-09', MonthWindow::current(new \DateTimeImmutable('2026-09-15'))->key());
    }
}
