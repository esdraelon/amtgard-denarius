<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Domain\Bank\Provider\Framework\Readiness\Impl\AlwaysReady;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\Registry\ConfiguredLedgerProviders;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\Readiness\Impl\PresentCredentials;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\Support\PreviousMonthWindow;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\Registry\ProviderAdmission;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Stripe\StripeApi;
use Amtgard\Denarius\Controller\WebhookController;
use Amtgard\Denarius\Utilities\Security\TokenCipher;
use Amtgard\Denarius\Service\Enrollment\EnrollmentService;
use Amtgard\Denarius\Service\Ledger\ProviderWebhookHandler;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Stripe\Impl\CurlStripeApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Stripe\StripeLedgerProvider;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Stripe\StripeWebhookVerifier;
use Amtgard\PHPUnit\AmtgardTestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response;

final class StripeAdapterTest extends AmtgardTestCase
{
    private const NOW = 1_758_000_000;

    private static ?string $base = null;

    /** @var resource|null */
    private static $server = null;

    public static function setUpBeforeClass(): void
    {
        $root = sys_get_temp_dir() . '/denarius-stripe';
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
if ($path === '/v1/customers') {
    echo '{"id":"cus_live"}';
    return;
}
if ($path === '/v1/financial_connections/sessions') {
    echo '{"id":"fcsess_1","client_secret":"cs_live"}';
    return;
}
if ($path === '/v1/financial_connections/accounts') {
    echo '{"data":[{"id":"fca_1","display_name":"Checking","subcategory":"checking","last4":"4242"}],"has_more":false}';
    return;
}
if (str_contains((string) $path, '/subscribe')) {
    echo '{"id":"fca_1"}';
    return;
}
if ($path === '/v1/financial_connections/transactions') {
    echo '{"data":[{"id":"fctxn_1","amount":300,"description":"Rocket","status":"posted","transacted_at":1758000000}],"has_more":false}';
    return;
}
echo 'hello';
PHP);
        $port = 30000 + (getmypid() % 10000);
        $command = sprintf('php -S 127.0.0.1:%d %s', $port, escapeshellarg($root . '/router.php'));
        $process = proc_open($command, [1 => ['file', $root . '/server.log', 'a'], 2 => ['file', $root . '/server.log', 'a']], $pipes);
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

    /** Scripted and local HTTP paths for method-log tests (skips long dead-port curl). */
    public static function exerciseCurlForMethodLog(?string $localBase = null): void
    {
        $localBase ??= self::$base;
        $api = new CurlStripeApi('https://api.stripe.com/', 'sk_test', function (string $method, string $url, array $fields): string {
            if (str_contains($url, '/v1/customers')) {
                return 'hello';
            }
            if (str_contains($url, '/sessions')) {
                return '{"client_secret":"cs"}';
            }
            if (str_contains($url, '/subscribe')) {
                return '{"id":"fca_1"}';
            }
            if (str_contains($url, 'starting_after=fctxn_1')) {
                return '{"data":[{"id":"fctxn_2"}],"has_more":true}';
            }
            if (str_contains($url, 'starting_after=fctxn_2')) {
                return '{"data":[],"has_more":false}';
            }
            if (str_contains($url, '/transactions')) {
                return '{"data":[{"id":"fctxn_1"},"skip"],"has_more":true}';
            }
            if (str_contains($url, 'starting_after=fca_1')) {
                return '{"data":[{"display_name":"no-id"}],"has_more":true}';
            }

            return '{"data":[{"id":"fca_1"},"skip"],"has_more":true}';
        });
        $api->createCustomer('golden');
        $api->createSession('cus_1');
        $api->accounts('cus_1');
        $api->subscribe('fca 1');
        $api->transactions('fca_1', 1, 2);

        if ($localBase !== null) {
            (new CurlStripeApi($localBase, 'sk_test'))->createCustomer('golden');
        }
    }

    public function testStripeConnectsAccountsAndNotices(): void
    {
        $api = new ScriptedStripe();
        $provider = $this->provider($api);
        $this->assertSame('stripe', $provider->id());
        $this->assertSame('Stripe-Signature', $provider->signatureHeader());
        $this->assertSame('unknown', $provider->supports('First Bank')->verdict());
        $this->assertTrue($provider->supports(' ')->rejected());
        $this->assertTrue($this->provider($api, new PresentCredentials(['']))->supports('First Bank')->rejected());

        $config = $provider->connectConfig('golden-plains');
        $this->assertSame('stripe', $config['provider']);
        $this->assertSame('cus_1', $config['customerId']);
        $this->assertSame('cs_test', $config['clientSecret']);
        $this->assertSame('', $config['publishableKey']);
        $this->assertSame('pk_test', $this->provider($api, null, 'pk_test')->connectConfig('golden-plains')['publishableKey']);
        $this->assertSame('golden-plains', $api->customers[0]);

        $blank = new ScriptedStripe(customer: []);
        $this->assertThrows(\RuntimeException::class, fn () => $this->provider($blank)->connectConfig('x'));
        $noSecret = new ScriptedStripe(session: ['id' => 'fcsess']);
        $this->assertThrows(\RuntimeException::class, fn () => $this->provider($noSecret)->connectConfig('x'));
        $this->assertThrows(\InvalidArgumentException::class, fn () => $provider->enrollment([]));

        $connected = $provider->enrollment(['customer' => 'cus_9', 'institutionName' => 'First Bank']);
        $this->assertSame('cus_9', $connected->accessToken);
        $this->assertSame('stripe', $connected->provider);
        $this->assertSame('First Bank', $connected->institutionName);

        $accounts = $provider->accounts('cus_1');
        $this->assertSame(['fca_1'], $api->subscribed);
        $this->assertSame('Checking', $accounts[0]->name);
        $this->assertSame('checking', $accounts[0]->type);
        $this->assertSame('4242', $accounts[0]->lastFour);
        $this->assertCount(1, $accounts);

        $named = new ScriptedStripe(accounts: [
            ['id' => 'fca_named', 'institution_name' => 'Credit Union', 'category' => 'credit'],
            ['id' => 'fca_2'],
        ]);
        $mapped = $this->provider($named)->accounts('cus_1');
        $this->assertSame('Credit Union', $mapped[0]->name);
        $this->assertSame('credit', $mapped[0]->type);
        $this->assertNull($mapped[0]->lastFour);
        $this->assertSame('Account', $mapped[1]->name);
        $this->assertSame('depository', $mapped[1]->type);

        $rows = $provider->transactions('cus_1', 'fca_1', null);
        $this->assertSame(['fctxn_1', 'fctxn_neg', 'fctxn_digit'], array_map(static fn ($row) => $row->id, $rows));
        $this->assertSame('fctxn_1', $rows[0]->id);
        $this->assertSame('3.00', $rows[0]->amount);
        $this->assertSame('-1.50', $rows[1]->amount);
        $this->assertSame('Rocket', $rows[0]->description);
        $this->assertSame('posted', $rows[0]->status);
        $this->assertSame('general', $rows[0]->category);
        $this->assertSame([], $provider->transactions('cus_1', 'fca_1', 'fctxn_1'));
        $this->assertSame($api->window[0], (new PreviousMonthWindow(new \DateTimeImmutable('@' . self::NOW)))->startsAt());

        $body = $this->event('financial_connections.account.refreshed_transactions', 'cus_1');
        $notice = $provider->notice($body, $this->sign($body), self::NOW);
        $this->assertTrue($notice->accepted);
        $this->assertSame('refresh', $notice->action);
        $this->assertSame('cus_1', $notice->enrollmentId);
        $disconnect = $this->event('financial_connections.account.disconnected', 'cus_1');
        $this->assertSame('disconnect', $provider->notice($disconnect, $this->sign($disconnect), self::NOW)->action);
        $other = $this->event('financial_connections.account.created', 'cus_1');
        $this->assertSame('', $provider->notice($other, $this->sign($other), self::NOW)->action);
        $empty = $this->event('financial_connections.account.refreshed_transactions', '');
        $this->assertTrue($provider->notice($empty, $this->sign($empty), self::NOW)->accepted);
        $this->assertSame('', $provider->notice($empty, $this->sign($empty), self::NOW)->enrollmentId);
        $this->assertFalse($provider->notice($body, 't=1,v1=nope', self::NOW)->accepted);
        $this->assertFalse($provider->notice('not-json', $this->sign('not-json'), self::NOW)->accepted);
    }

    public function testStripeSignatureRejectsStaleAndBlankSecrets(): void
    {
        $verifier = new StripeWebhookVerifier('whsec', 300);
        $now = self::NOW;
        $this->assertFalse($verifier->verify('body', null, $now));
        $this->assertFalse($verifier->verify('body', '', $now));
        $this->assertFalse($verifier->verify('body', 't=abc,v1=abc', $now));
        $this->assertFalse($verifier->verify('body', 't=' . $now, $now));
        $this->assertFalse($verifier->verify('body', 't=' . ($now - 500) . ',v1=abc', $now));
        $this->assertFalse($verifier->verify('body', 'v1=abc', $now));
        $signature = $this->sign('body');
        $this->assertTrue($verifier->verify('body', 't=' . $now . ',v1=dead,v1=' . hash_hmac('sha256', $now . '.body', 'whsec'), $now));
        $this->assertTrue($verifier->verify('body', $signature, $now));
        $this->assertFalse($verifier->verify('body', 't=' . $now . ',v1=dead', $now));
        $this->assertFalse((new StripeWebhookVerifier(''))->verify('body', $signature, $now));
    }

    public function testConfiguredProvidersKeepReadyOnesInOrder(): void
    {
        $stripe = $this->provider(new ScriptedStripe());
        $teller = Strategies::teller();
        $both = (new ConfiguredLedgerProviders([
            new ProviderAdmission($stripe, new AlwaysReady()),
            new ProviderAdmission($teller, new PresentCredentials(['app'])),
        ]))->registry();
        $this->assertSame('stripe', $both->default()->id());
        $tellerOnly = (new ConfiguredLedgerProviders([
            new ProviderAdmission($stripe, new PresentCredentials([''])),
            new ProviderAdmission($teller, new PresentCredentials(['app'])),
        ]))->registry();
        $this->assertSame('teller', $tellerOnly->default()->id());
        $none = (new ConfiguredLedgerProviders([
            new ProviderAdmission($stripe, new PresentCredentials([' '])),
        ]))->registry();
        $this->assertSame('', $none->default()->id());
    }

    public function testStripeClientPaginatesAndFallsBackToCurl(): void
    {
        $calls = [];
        $api = new CurlStripeApi('https://api.stripe.com/', 'sk_test', function (string $method, string $url, array $fields) use (&$calls): string {
            $calls[] = [$method, $url, $fields];
            if (str_contains($url, '/v1/customers')) {
                return 'hello';
            }
            if (str_contains($url, '/sessions')) {
                return '{"client_secret":"cs"}';
            }
            if (str_contains($url, '/subscribe')) {
                return '{"id":"fca_1"}';
            }
            if (str_contains($url, 'starting_after=fctxn_1')) {
                return '{"data":[{"id":"fctxn_2"}],"has_more":true}';
            }
            if (str_contains($url, 'starting_after=fctxn_2')) {
                return '{"data":[],"has_more":false}';
            }
            if (str_contains($url, '/transactions')) {
                return '{"data":[{"id":"fctxn_1"},"skip"],"has_more":true}';
            }
            if (str_contains($url, 'starting_after=fca_1')) {
                return '{"data":[{"display_name":"no-id"}],"has_more":true}';
            }

            return '{"data":[{"id":"fca_1"},"skip"],"has_more":true}';
        });
        $this->assertSame([], $api->createCustomer('golden'));
        $this->assertSame('cs', $api->createSession('cus_1')['client_secret']);
        $this->assertSame('fca_1', $api->accounts('cus_1')[0]['id']);
        $api->subscribe('fca 1');
        $this->assertSame(['fctxn_1', 'fctxn_2'], array_column($api->transactions('fca_1', 1, 2), 'id'));
        $this->assertSame('POST', $calls[0][0]);
        $this->assertStringContainsString('metadata%5Bkingdom%5D=golden', http_build_query($calls[0][2]));
        $urls = array_map(static fn (array $call) => $call[1], $calls);
        $this->assertTrue(array_any($urls, static fn (string $url) => str_contains($url, 'starting_after=fctxn_1')));

        if (self::$base === null) {
            $this->fail('Local HTTP server did not start.');
        }
        $live = new CurlStripeApi(self::$base, 'sk_test');
        $this->assertSame('cus_live', $live->createCustomer('golden')['id']);
        $this->assertSame('cs_live', $live->createSession('cus_live')['client_secret']);
        $this->assertSame('fca_1', $live->accounts('cus_live')[0]['id']);
        $live->subscribe('fca_1');
        $this->assertSame('fctxn_1', $live->transactions('fca_1', 1, 2)[0]['id']);
        $this->assertSame([], (new CurlStripeApi(self::$base . '/text', 'sk_test'))->createCustomer('golden'));
        $this->assertThrows(\RuntimeException::class, fn () => (new CurlStripeApi(self::$base . '/fail', 'sk_test'))->accounts('cus'));
        $this->assertThrows(\RuntimeException::class, fn () => (new CurlStripeApi('http://127.0.0.1:1', 'sk_test'))->createCustomer('golden'));
    }

    public function testStripeWebhookRouteAcknowledgesASignedRefresh(): void
    {
        $provider = $this->provider(new ScriptedStripe());
        $kingdoms = new MemoryKingdoms();
        $kingdoms->save(\Amtgard\Denarius\Persistence\Record\KingdomRecord::builder()->orkKingdomId(4)->name('Golden Plains')->slug('golden-plains')->enrollmentId('cus_1')->provider('stripe')->enrollmentStatus('connected')->build());
        $queue = new MemoryRefresh();
        $enrollment = new EnrollmentService($kingdoms, new MemorySecrets(), new MemoryAccounts(), Strategies::providers($provider), new TokenCipher('k'), $queue, Strategies::months());
        $webhook = new WebhookController(new ProviderWebhookHandler(Strategies::providers($provider), $kingdoms, Strategies::events($queue, $enrollment)));
        $this->assertSame(400, $webhook->stripe((new ServerRequestFactory())->createServerRequest('POST', '/webhooks/stripe'), new Response())->getStatusCode());

        $body = $this->event('financial_connections.account.refreshed_transactions', 'cus_1');
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/webhooks/stripe')
            ->withHeader('Stripe-Signature', $this->sign($body, time()))
            ->withBody((new StreamFactory())->createStream($body));
        $this->assertSame(200, $webhook->stripe($request, new Response())->getStatusCode());
        $this->assertSame(4, $queue->ledger[0]);
    }

    private function provider(StripeApi $api, ?PresentCredentials $ready = null, string $publishableKey = ''): StripeLedgerProvider
    {
        return new StripeLedgerProvider(
            $api,
            new StripeWebhookVerifier('whsec', 300),
            StripeLedgerProvider::actions(),
            $ready ?? new AlwaysReady(),
            new PreviousMonthWindow(new \DateTimeImmutable('@' . self::NOW)),
            $publishableKey,
        );
    }

    private function event(string $type, string $customer): string
    {
        return json_encode([
            'type' => $type,
            'data' => ['object' => ['account_holder' => ['customer' => $customer]]],
        ], JSON_THROW_ON_ERROR);
    }

    private function sign(string $body, ?int $now = null): string
    {
        $timestamp = $now ?? self::NOW;

        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, 'whsec');
    }
}

final class ScriptedStripe implements StripeApi
{
    /** @var list<string> */
    public array $customers = [];

    /** @var list<string> */
    public array $subscribed = [];

    /** @var list<int> */
    public array $window = [];

    /**
     * @param array<string, mixed> $customer
     * @param array<string, mixed> $session
     * @param list<array<string, mixed>> $accounts
     * @param list<array<string, mixed>> $transactions
     */
    public function __construct(
        private readonly array $customer = ['id' => 'cus_1'],
        private readonly array $session = ['client_secret' => 'cs_test'],
        private readonly array $accounts = [
            ['id' => 'fca_1', 'display_name' => 'Checking', 'subcategory' => 'checking', 'last4' => '4242'],
            ['id' => ''],
            ['display_name' => 'Skip'],
        ],
        private readonly array $transactions = [],
    ) {
    }

    public function createCustomer(string $kingdomKey): array
    {
        $this->customers[] = $kingdomKey;

        return $this->customer;
    }

    public function createSession(string $customerId): array
    {
        return $this->session;
    }

    public function accounts(string $customerId): array
    {
        return $this->accounts;
    }

    public function subscribe(string $accountId): void
    {
        $this->subscribed[] = $accountId;
    }

    public function transactions(string $accountId, int $startsAt, int $endsAt): array
    {
        $this->window = [$startsAt, $endsAt];
        if ($this->transactions !== []) {
            return $this->transactions;
        }
        $inside = 1_758_000_000;

        return [
            ['id' => 'fctxn_1', 'amount' => 300, 'description' => 'Rocket', 'status' => 'posted', 'transacted_at' => $inside],
            ['id' => 'fctxn_neg', 'amount' => -150, 'description' => 'Refund', 'status' => 'pending', 'transacted_at' => $inside],
            ['id' => 'fctxn_old', 'amount' => 10, 'description' => 'Old', 'status' => 'posted', 'transacted_at' => $inside - 60 * 86400],
            ['id' => 'fctxn_bad', 'amount' => 10, 'transacted_at' => 'soon'],
            ['amount' => 10, 'transacted_at' => $inside],
            ['id' => 'fctxn_digit', 'amount' => 50, 'status' => 'void', 'transacted_at' => (string) $inside],
        ];
    }
}
