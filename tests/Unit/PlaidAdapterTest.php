<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Bank\AlwaysReady;
use Amtgard\Denarius\Bank\ConfiguredLedgerProviders;
use Amtgard\Denarius\Bank\PresentCredentials;
use Amtgard\Denarius\Bank\PreviousMonthWindow;
use Amtgard\Denarius\Bank\ProviderAdmission;
use Amtgard\Denarius\Bank\Plaid\PlaidApi;
use Amtgard\Denarius\Controller\WebhookController;
use Amtgard\Denarius\Bank\Plaid\CurlPlaidApi;
use Amtgard\Denarius\Bank\Plaid\PlaidLedgerProvider;
use Amtgard\Denarius\Bank\Plaid\PlaidWebhookVerifier;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Utilities\Security\TokenCipher;
use Amtgard\Denarius\Service\EnrollmentService;
use Amtgard\Denarius\Service\ProviderWebhookHandler;
use Amtgard\PHPUnit\AmtgardTestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response;

final class PlaidAdapterTest extends AmtgardTestCase
{
    private const NOW = 1_758_000_000;

    private static ?string $base = null;

    /** @var resource|null */
    private static $server = null;

    public static function setUpBeforeClass(): void
    {
        $root = sys_get_temp_dir() . '/denarius-plaid';
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
if ($path === '/institutions/search') {
    echo '{"institutions":[{"name":"First Bank"},"skip"]}';
    return;
}
if ($path === '/link/token/create') {
    echo '{"link_token":"link-sandbox"}';
    return;
}
if ($path === '/item/public_token/exchange') {
    echo '{"access_token":"access-sandbox","item_id":"item_1"}';
    return;
}
if ($path === '/accounts/get') {
    echo '{"accounts":[{"account_id":"acc","name":"Checking","type":"depository","mask":"1234"}]}';
    return;
}
if ($path === '/transactions/sync') {
    echo '{"added":[{"transaction_id":"tx"}],"modified":[],"has_more":false,"next_cursor":"done"}';
    return;
}
if ($path === '/webhook_verification_key/get') {
    echo '{"key":{"kty":"EC","kid":"kid_1"}}';
    return;
}
echo 'hello';
PHP);
        $port = 31000 + (getmypid() % 10000);
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

    public function testPlaidLinksAccountsAndNotices(): void
    {
        $api = new ScriptedPlaid();
        $provider = $this->provider($api);
        $this->assertSame('plaid', $provider->id());
        $this->assertSame('Plaid-Verification', $provider->signatureHeader());
        $this->assertSame('yes', $provider->supports('First Bank')->verdict());
        $this->assertTrue($provider->supports(' ')->rejected());
        $this->assertTrue($this->provider($api, new PresentCredentials(['id', '']))->supports('First Bank')->rejected());
        $this->assertSame('no', $this->provider(new ScriptedPlaid(institutions: []))->supports('Missing')->verdict());
        $this->assertSame('unknown', $this->provider(new ScriptedPlaid(throwOnSearch: true))->supports('First Bank')->verdict());

        $config = $provider->connectConfig('golden-plains');
        $this->assertSame('link-sandbox', $config['linkToken']);
        $this->assertSame('plaid', $config['provider']);
        $this->assertThrows(\RuntimeException::class, fn () => $this->provider(new ScriptedPlaid(link: []))->connectConfig('x'));
        $this->assertThrows(\InvalidArgumentException::class, fn () => $provider->enrollment([]));
        $this->assertThrows(\RuntimeException::class, fn () => $provider->enrollment(['public_token' => 'public']));
        $api->exchange = ['access_token' => 'access-sandbox', 'item_id' => 'item_1'];
        $connected = $provider->enrollment(['publicToken' => 'public', 'institutionName' => 'First Bank']);
        $this->assertSame('access-sandbox', $connected->accessToken);
        $this->assertSame('item_1', $connected->enrollmentId);
        $this->assertSame('plaid', $connected->provider);

        $accounts = $provider->accounts('access-sandbox');
        $this->assertSame('acc', $accounts[0]->id);
        $this->assertSame('Checking', $accounts[0]->name);
        $this->assertSame('1234', $accounts[0]->lastFour);
        $plain = $this->provider(new ScriptedPlaid(accounts: [['account_id' => 'acc_2']]))->accounts('access');
        $this->assertSame('Account', $plain[0]->name);
        $this->assertSame('depository', $plain[0]->type);
        $this->assertNull($plain[0]->lastFour);

        $rows = $provider->transactions('access-sandbox', 'acc', null);
        $this->assertSame(['tx_1', 'tx_pending'], array_map(static fn ($row) => $row->id, $rows));
        $this->assertSame('12.50', $rows[0]->amount);
        $this->assertSame('FOOD_AND_DRINK', $rows[0]->category);
        $this->assertSame('Cafe', $rows[0]->counterparty);
        $this->assertSame('posted', $rows[0]->status);
        $this->assertSame('pending', $rows[1]->status);
        $this->assertSame('0.00', $rows[1]->amount);
        $this->assertSame([], $provider->transactions('access-sandbox', 'acc', 'tx_1'));

        $body = '{"webhook_code":"SYNC_UPDATES_AVAILABLE","item_id":"item_1"}';
        $notice = $provider->notice($body, $this->jwt($api, $body), self::NOW);
        $this->assertSame('refresh', $notice->action);
        $this->assertSame('item_1', $notice->enrollmentId);
        $disconnect = '{"webhook_code":"USER_PERMISSION_REVOKED","item_id":"item_1"}';
        $this->assertSame('disconnect', $provider->notice($disconnect, $this->jwt($api, $disconnect), self::NOW)->action);
        $other = '{"webhook_code":"ERROR","item_id":"item_1"}';
        $this->assertSame('', $provider->notice($other, $this->jwt($api, $other), self::NOW)->action);
        $empty = '{"webhook_code":"SYNC_UPDATES_AVAILABLE"}';
        $this->assertSame('', $provider->notice($empty, $this->jwt($api, $empty), self::NOW)->enrollmentId);
        $this->assertFalse($provider->notice($body, 'a.b.c', self::NOW)->accepted);
        $this->assertFalse($provider->notice('not-json', $this->jwt($api, 'not-json'), self::NOW)->accepted);
    }

    public function testPlaidVerificationRejectsBadTokens(): void
    {
        $api = new ScriptedPlaid();
        $verifier = new PlaidWebhookVerifier($api, 300);
        $body = '{"ok":true}';
        $this->assertFalse($verifier->verify($body, null, self::NOW));
        $this->assertFalse($verifier->verify($body, '', self::NOW));
        $this->assertFalse($verifier->verify($body, 'only-one', self::NOW));
        $this->assertFalse($verifier->verify($body, 'a..c', self::NOW));
        $this->assertFalse($verifier->verify($body, $this->token(['alg' => 'HS256', 'kid' => 'kid_1'], ['iat' => self::NOW], $api->privateKey), self::NOW));
        $this->assertFalse($verifier->verify($body, $this->token(['alg' => 'ES256'], ['iat' => self::NOW], $api->privateKey), self::NOW));
        $api->key['kty'] = 'RSA';
        $this->assertFalse($verifier->verify($body, $this->jwt($api, $body), self::NOW));
        $api->key['kty'] = 'EC';
        $api->key['expired_at'] = self::NOW - 10;
        $this->assertFalse($verifier->verify($body, $this->jwt($api, $body), self::NOW));
        $api->key['expired_at'] = null;
        $this->assertFalse($verifier->verify($body, $this->token(['alg' => 'ES256', 'kid' => 'kid_1'], ['iat' => self::NOW - 500, 'request_body_sha256' => hash('sha256', $body)], $api->privateKey), self::NOW));
        $this->assertFalse($verifier->verify($body, $this->token(['alg' => 'ES256', 'kid' => 'kid_1'], ['iat' => self::NOW, 'request_body_sha256' => 'short'], $api->privateKey), self::NOW));
        $this->assertFalse($verifier->verify($body, $this->token(['alg' => 'ES256', 'kid' => 'kid_1'], ['iat' => self::NOW, 'request_body_sha256' => str_repeat('a', 64)], $api->privateKey), self::NOW));
        $api->key['x'] = 'aa';
        $this->assertFalse($verifier->verify($body, $this->jwt($api, $body), self::NOW));
        $fresh = new ScriptedPlaid();
        $this->assertTrue((new PlaidWebhookVerifier($fresh, 300))->verify($body, $this->jwt($fresh, $body), self::NOW));
    }

    public function testPlaidClientPagesAndFallsBackToCurl(): void
    {
        $api = new CurlPlaidApi('https://sandbox.plaid.com/', 'id', 'secret', 'Denarius', function (string $url, array $body): string {
            $this->assertSame('id', $body['client_id']);
            if (str_contains($url, '/institutions/search')) {
                return 'hello';
            }
            if (str_contains($url, '/link/token/create')) {
                return '{"link_token":"link"}';
            }
            if (str_contains($url, '/exchange')) {
                return '{"access_token":"access"}';
            }
            if (str_contains($url, '/accounts/get')) {
                return '{"accounts":"nope"}';
            }
            if (str_contains($url, '/webhook_verification_key/get')) {
                return '{"key":"nope"}';
            }
            if (($body['cursor'] ?? '') === 'next') {
                return '{"added":[],"modified":[{"transaction_id":"t2"}],"has_more":true,"next_cursor":""}';
            }

            return '{"added":[{"transaction_id":"t1"},"skip"],"modified":[],"has_more":true,"next_cursor":"next"}';
        });
        $this->assertSame([], $api->institutions('First'));
        $this->assertSame('link', $api->linkToken('golden')['link_token']);
        $this->assertSame('access', $api->exchange('public')['access_token']);
        $this->assertSame([], $api->accounts('access'));
        $this->assertSame([], $api->verificationKey('kid'));
        $this->assertSame(['t1', 't2'], array_column($api->transactions('access'), 'transaction_id'));

        if (self::$base === null) {
            $this->fail('Local HTTP server did not start.');
        }
        $live = new CurlPlaidApi(self::$base, 'id', 'secret', 'Denarius');
        $this->assertSame('First Bank', $live->institutions('First')[0]['name']);
        $this->assertSame('link-sandbox', $live->linkToken('golden')['link_token']);
        $this->assertSame('access-sandbox', $live->exchange('public')['access_token']);
        $this->assertSame('acc', $live->accounts('access')[0]['account_id']);
        $this->assertSame('tx', $live->transactions('access')[0]['transaction_id']);
        $this->assertSame('kid_1', $live->verificationKey('kid_1')['kid']);
        $this->assertSame([], (new CurlPlaidApi(self::$base . '/text', 'id', 'secret', 'Denarius'))->linkToken('golden'));
        $this->assertThrows(\RuntimeException::class, fn () => (new CurlPlaidApi(self::$base . '/fail', 'id', 'secret', 'Denarius'))->accounts('access'));
        $this->assertThrows(\RuntimeException::class, fn () => (new CurlPlaidApi('http://127.0.0.1:1', 'id', 'secret', 'Denarius'))->linkToken('golden'));
    }

    public function testPlaidIsAdmittedBetweenStripeAndTeller(): void
    {
        $plaid = $this->provider(new ScriptedPlaid());
        $registry = (new ConfiguredLedgerProviders([
            new ProviderAdmission($plaid, new PresentCredentials(['id', 'secret'])),
            new ProviderAdmission(Strategies::teller(), new PresentCredentials(['app'])),
        ]))->registry();
        $this->assertSame('plaid', $registry->default()->id());
        $skipped = (new ConfiguredLedgerProviders([
            new ProviderAdmission($plaid, new PresentCredentials(['id', ''])),
            new ProviderAdmission(Strategies::teller(), new AlwaysReady()),
        ]))->registry();
        $this->assertSame('teller', $skipped->default()->id());
    }

    public function testPlaidWebhookRouteAcknowledgesASignedRefresh(): void
    {
        $api = new ScriptedPlaid();
        $provider = $this->provider($api);
        $kingdoms = new MemoryKingdoms();
        $kingdoms->save(KingdomRecord::builder()->orkKingdomId(4)->name('Golden Plains')->slug('golden-plains')->enrollmentId('item_1')->provider('plaid')->enrollmentStatus('connected')->build());
        $queue = new MemoryRefresh();
        $enrollment = new EnrollmentService($kingdoms, new MemorySecrets(), new MemoryAccounts(), Strategies::providers($provider), new TokenCipher('k'), $queue, Strategies::months());
        $webhook = new WebhookController(new ProviderWebhookHandler(Strategies::providers($provider), $kingdoms, Strategies::events($queue, $enrollment)));
        $this->assertSame(400, $webhook->plaid((new ServerRequestFactory())->createServerRequest('POST', '/webhooks/plaid'), new Response())->getStatusCode());
        $body = '{"webhook_code":"DEFAULT_UPDATE","item_id":"item_1"}';
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/webhooks/plaid')
            ->withHeader('Plaid-Verification', $this->jwt($api, $body, time()))
            ->withBody((new StreamFactory())->createStream($body));
        $this->assertSame(200, $webhook->plaid($request, new Response())->getStatusCode());
        $this->assertSame(4, $queue->ledger[0]);
    }

    private function provider(PlaidApi $api, ?PresentCredentials $ready = null): PlaidLedgerProvider
    {
        return new PlaidLedgerProvider(
            $api,
            new PlaidWebhookVerifier($api, 300),
            PlaidLedgerProvider::actions(),
            $ready ?? new AlwaysReady(),
            new PreviousMonthWindow(new \DateTimeImmutable('@' . self::NOW)),
        );
    }

    private function jwt(ScriptedPlaid $api, string $body, ?int $now = null): string
    {
        return $this->token(
            ['alg' => 'ES256', 'kid' => 'kid_1', 'typ' => 'JWT'],
            ['iat' => $now ?? self::NOW, 'request_body_sha256' => hash('sha256', $body)],
            $api->privateKey,
        );
    }

    /**
     * @param array<string, mixed> $header
     * @param array<string, mixed> $claims
     */
    private function token(array $header, array $claims, \OpenSSLAsymmetricKey $key): string
    {
        $encoded = $this->b64((string) json_encode($header)) . '.' . $this->b64((string) json_encode($claims));
        openssl_sign($encoded, $der, $key, OPENSSL_ALGO_SHA256);

        return $encoded . '.' . $this->b64($this->raw($der));
    }

    private function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function raw(string $der): string
    {
        $offset = ord($der[1]) === 0x81 ? 3 : 2;
        $rLength = ord($der[$offset + 1]);
        $r = substr($der, $offset + 2, $rLength);
        $offset += 2 + $rLength;
        $sLength = ord($der[$offset + 1]);
        $s = substr($der, $offset + 2, $sLength);

        return str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT) . str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
    }
}

final class ScriptedPlaid implements PlaidApi
{
    public \OpenSSLAsymmetricKey $privateKey;

    /** @var array<string, mixed> */
    public array $key;

    /** @var array<string, mixed> */
    public array $exchange = [];

    /**
     * @param list<array<string, mixed>> $institutions
     * @param array<string, mixed> $link
     * @param list<array<string, mixed>> $accounts
     */
    public function __construct(
        public array $institutions = [['name' => 'First Bank']],
        public array $link = ['link_token' => 'link-sandbox'],
        public array $accounts = [
            ['account_id' => 'acc', 'name' => 'Checking', 'type' => 'depository', 'mask' => '1234'],
            ['name' => 'Skip'],
        ],
        public bool $throwOnSearch = false,
    ) {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($key === false) {
            throw new \RuntimeException('Unable to create a Plaid test key.');
        }
        $this->privateKey = $key;
        $details = openssl_pkey_get_details($key);
        $this->key = [
            'alg' => 'ES256',
            'crv' => 'P-256',
            'kid' => 'kid_1',
            'kty' => 'EC',
            'use' => 'sig',
            'x' => rtrim(strtr(base64_encode($details['ec']['x']), '+/', '-_'), '='),
            'y' => rtrim(strtr(base64_encode($details['ec']['y']), '+/', '-_'), '='),
            'expired_at' => null,
        ];
    }

    public function institutions(string $query): array
    {
        if ($this->throwOnSearch) {
            throw new \RuntimeException('Plaid search failed.');
        }

        return $this->institutions;
    }

    public function linkToken(string $kingdomKey): array
    {
        return $this->link;
    }

    public function exchange(string $publicToken): array
    {
        return $this->exchange;
    }

    public function accounts(string $accessToken): array
    {
        return $this->accounts;
    }

    public function transactions(string $accessToken): array
    {
        return [
            [
                'transaction_id' => 'tx_1',
                'account_id' => 'acc',
                'amount' => 12.5,
                'date' => '2025-09-16',
                'name' => 'Coffee',
                'merchant_name' => 'Cafe',
                'pending' => false,
                'personal_finance_category' => ['primary' => 'FOOD_AND_DRINK'],
            ],
            [
                'transaction_id' => 'tx_pending',
                'account_id' => 'acc',
                'amount' => 'nope',
                'date' => '2025-08-01',
                'name' => 'Hold',
                'pending' => true,
            ],
            ['transaction_id' => 'tx_old', 'account_id' => 'acc', 'amount' => 1, 'date' => '2025-07-01'],
            ['transaction_id' => 'tx_other', 'account_id' => 'other', 'amount' => 1, 'date' => '2025-09-16'],
            ['account_id' => 'acc', 'amount' => 1, 'date' => '2025-09-16'],
            ['transaction_id' => 'tx_bad', 'account_id' => 'acc', 'amount' => 1, 'date' => 'soon'],
        ];
    }

    public function verificationKey(string $keyId): array
    {
        return $this->key;
    }
}
