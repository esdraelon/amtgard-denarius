<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Domain\Bank\Provider\Framework\Readiness\Impl\AlwaysReady;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\Registry\ConfiguredLedgerProviders;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\Readiness\Impl\PresentCredentials;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\Support\PreviousMonthWindow;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\Registry\ProviderAdmission;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\SimpleFinApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\Impl\CurlSimpleFinApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\SimpleFinHost;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\SimpleFinLedgerProvider;
use Amtgard\PHPUnit\AmtgardTestCase;

final class SimpleFinAdapterTest extends AmtgardTestCase
{
    private const NOW = 1_758_000_000;

    private static ?string $base = null;

    /** @var resource|null */
    private static $server = null;

    public static function setUpBeforeClass(): void
    {
        $root = sys_get_temp_dir() . '/denarius-simplefin';
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
if (str_contains((string) $path, '/text')) {
    echo 'hello';
    return;
}
if ($path === '/claim') {
    echo "https://user:secret@bridge.simplefin.org/simplefin\n";
    return;
}
if (str_contains((string) $path, '/accounts')) {
    echo '{"accounts":[{"id":"acc","name":"Checking","transactions":[]},"skip"]}';
    return;
}
echo 'hello';
PHP);
        $port = 32000 + (getmypid() % 10000);
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

    public function testSimpleFinClaimsAccountsInsideTheWindow(): void
    {
        $api = new ScriptedSimpleFin();
        $provider = $this->provider($api);
        $this->assertSame('simplefin', $provider->id());
        $this->assertSame('', $provider->signatureHeader());
        $this->assertSame('unknown', $provider->supports('First Bank')->verdict());
        $this->assertTrue($provider->supports(' ')->rejected());
        $this->assertTrue($this->provider($api, new PresentCredentials(['']))->supports('First Bank')->rejected());
        $this->assertSame(['provider' => 'simplefin', 'kingdomKey' => 'golden'], $provider->connectConfig('golden'));
        $this->assertTrue($provider->notice('nope', null, self::NOW)->accepted);
        $this->assertSame('', $provider->notice('nope', null, self::NOW)->action);

        $token = base64_encode('https://bridge.simplefin.org/simplefin/claim/abc');
        $connected = $provider->enrollment(['setupToken' => $token, 'institutionName' => 'First Bank']);
        $this->assertSame('https://user:secret@bridge.simplefin.org/simplefin', $connected->accessToken);
        $this->assertSame('user', $connected->enrollmentId);
        $this->assertSame('simplefin', $connected->provider);
        $hashed = $this->provider(new ScriptedSimpleFin('https://bridge.simplefin.org/simplefin'))->enrollment(['setup_token' => $token]);
        $this->assertSame(hash('sha256', 'https://bridge.simplefin.org/simplefin'), $hashed->enrollmentId);
        $this->assertThrows(\InvalidArgumentException::class, fn () => $provider->enrollment([]));
        $this->assertThrows(\InvalidArgumentException::class, fn () => $provider->enrollment(['setupToken' => base64_encode('not-a-url')]));
        $this->assertThrows(\RuntimeException::class, fn () => $this->provider(new ScriptedSimpleFin(''))->enrollment(['setupToken' => $token]));

        $accounts = $provider->accounts($connected->accessToken);
        $this->assertSame('Checking', $accounts[0]->name);
        $this->assertNull($accounts[0]->lastFour);
        $plain = $this->provider(new ScriptedSimpleFin(accounts: [['id' => 'acc_2']]))->accounts('access');
        $this->assertSame('Account', $plain[0]->name);

        $rows = $provider->transactions($connected->accessToken, 'acc', null);
        $this->assertSame(['tx_1', 'tx_pending'], array_map(static fn ($row) => $row->id, $rows));
        $this->assertSame('-4.50', $rows[0]->amount);
        $this->assertSame('Coffee', $rows[0]->description);
        $this->assertSame('posted', $rows[0]->status);
        $this->assertSame('pending', $rows[1]->status);
        $this->assertSame('0.00', $rows[1]->amount);
        $this->assertSame([], $provider->transactions($connected->accessToken, 'acc', 'tx_1'));
    }

    public function testSimpleFinHostAllowsOnlyConfiguredBridges(): void
    {
        $hosts = new SimpleFinHost(['simplefin.org']);
        $this->assertTrue($hosts->accepts('https://bridge.simplefin.org/simplefin/claim/abc'));
        $this->assertTrue($hosts->accepts('https://beta-bridge.simplefin.org/simplefin'));
        $this->assertFalse($hosts->accepts('http://bridge.simplefin.org/simplefin'));
        $this->assertFalse($hosts->accepts('https://evil.example/simplefin'));
        $this->assertFalse($hosts->accepts('not a url'));
        $local = new SimpleFinHost(['127.0.0.1'], true);
        $this->assertTrue($local->accepts('http://127.0.0.1:32000/claim'));
        $this->assertFalse($local->accepts('http://evil.example/claim'));
    }

    public function testSimpleFinClientRejectsForeignUrlsAndUsesCurl(): void
    {
        $api = new CurlSimpleFinApi(new SimpleFinHost(['simplefin.org']), function (string $method, string $url): string {
            if ($method === 'POST') {
                return " \n";
            }
            if (str_contains($url, 'start-date=')) {
                return '{"accounts":"nope"}';
            }

            return 'hello';
        });
        $this->assertSame('', $api->claim('https://bridge.simplefin.org/simplefin/claim/abc'));
        $this->assertSame([], $api->accounts('https://user:secret@bridge.simplefin.org/simplefin', 1, 2));
        $this->assertThrows(\InvalidArgumentException::class, fn () => $api->claim('https://evil.example/claim'));

        if (self::$base === null) {
            $this->fail('Local HTTP server did not start.');
        }
        $live = new CurlSimpleFinApi(new SimpleFinHost(['127.0.0.1'], true));
        $this->assertSame('https://user:secret@bridge.simplefin.org/simplefin', $live->claim(self::$base . '/claim'));
        $access = str_replace('http://', 'http://user:secret@', (string) self::$base) . '/simplefin';
        $this->assertSame('acc', $live->accounts($access, 1, 2)[0]['id']);
        $this->assertSame([], (new CurlSimpleFinApi(new SimpleFinHost(['127.0.0.1'], true)))->accounts(self::$base . '/text', 1, 2));
        $this->assertThrows(\RuntimeException::class, fn () => (new CurlSimpleFinApi(new SimpleFinHost(['127.0.0.1'], true)))->claim(self::$base . '/fail'));
        $this->assertThrows(\RuntimeException::class, fn () => (new CurlSimpleFinApi(new SimpleFinHost(['127.0.0.1'], true)))->claim('http://127.0.0.1:1/claim'));
    }

    public function testSimpleFinStaysBehindTheEarlierProviders(): void
    {
        $simple = $this->provider(new ScriptedSimpleFin());
        $registry = (new ConfiguredLedgerProviders([
            new ProviderAdmission(Strategies::teller(), new PresentCredentials(['app'])),
            new ProviderAdmission($simple, new AlwaysReady()),
        ]))->registry();
        $this->assertSame('teller', $registry->default()->id());
        $only = (new ConfiguredLedgerProviders([
            new ProviderAdmission(Strategies::teller(), new PresentCredentials([''])),
            new ProviderAdmission($simple, new AlwaysReady()),
        ]))->registry();
        $this->assertSame('simplefin', $only->default()->id());
        $this->assertSame('simplefin', $only->resolve('First Bank', ['teller'])->id());
    }

    private function provider(SimpleFinApi $api, ?PresentCredentials $ready = null): SimpleFinLedgerProvider
    {
        return new SimpleFinLedgerProvider(
            $api,
            $ready ?? new AlwaysReady(),
            new PreviousMonthWindow(new \DateTimeImmutable('@' . self::NOW)),
        );
    }
}

final class ScriptedSimpleFin implements SimpleFinApi
{
    /**
     * @param list<array<string, mixed>> $accounts
     */
    public function __construct(
        private readonly string $accessUrl = 'https://user:secret@bridge.simplefin.org/simplefin',
        private readonly array $accounts = [],
    ) {
    }

    public function claim(string $claimUrl): string
    {
        return $this->accessUrl;
    }

    public function accounts(string $accessUrl, int $startsAt, int $endsAt): array
    {
        if ($this->accounts !== []) {
            return $this->accounts;
        }
        $inside = 1_758_000_000;

        return [
            [
                'id' => 'acc',
                'name' => 'Checking',
                'transactions' => [
                    ['id' => 'tx_1', 'posted' => $inside, 'amount' => '-4.50', 'description' => 'Coffee', 'pending' => false],
                    ['id' => 'tx_pending', 'posted' => (string) ($inside - 10 * 86400), 'amount' => 'nope', 'description' => 'Hold', 'pending' => true],
                    ['id' => 'tx_old', 'posted' => $inside - 60 * 86400, 'amount' => '1.00', 'description' => 'Old'],
                    ['posted' => $inside, 'amount' => '1.00'],
                    ['id' => 'tx_bad', 'posted' => 'soon', 'amount' => '1.00'],
                    'skip',
                ],
            ],
            ['name' => 'Skip'],
            'skip',
        ];
    }
}
