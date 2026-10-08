<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Utilities\Setup\Io\Impl\ConsoleIo;
use Amtgard\Denarius\Utilities\Setup\Client\Impl\CurlSetupClient;
use Amtgard\Denarius\Utilities\Setup\Env\EnvFragment;
use Amtgard\Denarius\Utilities\Setup\Io\HiddenLine;
use Amtgard\Denarius\Utilities\Setup\Guide\Impl\PlaidGuide;
use Amtgard\Denarius\Utilities\Setup\ProviderSetup;
use Amtgard\Denarius\Utilities\Setup\Field\RequiredSettings;
use Amtgard\Denarius\Utilities\Setup\Client\SetupClient;
use Amtgard\Denarius\Utilities\Setup\Guide\Impl\SimpleFinGuide;
use Amtgard\Denarius\Utilities\Setup\Guide\Impl\StripeGuide;
use Amtgard\Denarius\Utilities\Setup\Guide\Impl\TellerGuide;
use Amtgard\Denarius\Utilities\Setup\Io\TextIo;
use Amtgard\PHPUnit\AmtgardTestCase;

final class ProviderSetupTest extends AmtgardTestCase
{
    private const SECRET = 'sk_test_super_secret';

    public function testVerifiedProvidersAreWrittenWithoutPrintingSecrets(): void
    {
        $client = new ScriptedSetupClient(readable: ['/tmp/teller.pem' => true, '/tmp/teller.key' => true]);
        $io = new ScriptedIo([
            self::SECRET,
            'pk_test_public',
            'whsec_stripe',
            'client-id',
            'plaid-secret-value',
            'app_test',
            '/tmp/teller.pem',
            '/tmp/teller.key',
            'whsec_teller',
            'amtgard_denarius_dev',
            'simplefin-app-token',
        ]);
        $path = $this->path();
        $code = $this->wired($client, $io)->run($path);

        $this->assertSame(0, $code);
        $written = implode('', $io->written);
        $this->assertStringNotContainsString(self::SECRET, $written);
        $this->assertStringNotContainsString('plaid-secret-value', $written);
        $this->assertStringNotContainsString('whsec_teller', $written);
        $this->assertStringContainsString("stripe verified.\n", $written);
        $this->assertStringContainsString("plaid verified.\n", $written);
        $this->assertStringContainsString("teller verified.\n", $written);
        $this->assertStringContainsString("simplefin verified.\n", $written);
        $this->assertStringContainsString('mode 0600. Do not commit this file.', $written);
        $this->assertGreaterThanOrEqual(10, count($io->hidden));
        $this->assertTrue($io->hidden[2]);
        $this->assertTrue($io->hidden[4]);

        $file = (string) file_get_contents($path);
        $this->assertStringContainsString('STRIPE_SECRET_KEY="' . self::SECRET . '"', $file);
        $this->assertStringContainsString('PLAID_SECRET="plaid-secret-value"', $file);
        $this->assertSame(0600, fileperms($path) & 0777);
        $this->assertSame('GET', $client->calls[0][0]);
        $this->assertSame('https://api.stripe.test/v1/account', $client->calls[0][1]);
        $this->assertSame(['Authorization: Bearer ' . self::SECRET], $client->calls[0][2]);
        $this->assertSame('POST', $client->calls[1][0]);
        $this->assertSame('https://plaid.test/institutions/get', $client->calls[1][1]);
        $this->assertStringContainsString('"country_codes":["US"]', $client->calls[1][3]);
        $this->assertStringContainsString('"secret":"plaid-secret-value"', $client->calls[1][3]);
    }

    public function testARejectedProviderIsOmittedAndAnEmptyRunWritesNothing(): void
    {
        $client = new ScriptedSetupClient(status: 401, readable: ['/tmp/teller.pem' => true, '/tmp/teller.key' => false]);
        $io = new ScriptedIo([
            self::SECRET,
            'pk_test_public',
            'whsec_stripe',
            ' ',
            'plaid-secret-value',
            'app_test',
            '/tmp/teller.pem',
            '/tmp/teller.key',
            'whsec_teller',
            '',
            '',
        ]);
        $path = $this->path();
        $code = $this->wired($client, $io)->run($path);

        $this->assertSame(1, $code);
        $this->assertFileDoesNotExist($path);
        $written = implode('', $io->written);
        $this->assertStringContainsString('Stripe rejected the credentials.', $written);
        $this->assertStringContainsString('Plaid rejected the credentials.', $written);
        $this->assertStringContainsString('Teller rejected the credentials.', $written);
        $this->assertStringContainsString('No credentials were written.', $written);
        $this->assertStringNotContainsString(self::SECRET, $written);
        $this->assertStringNotContainsString('plaid-secret-value', $written);
        $this->assertCount(1, $client->calls);
    }

    public function testGuidesDescribeTheHumanSteps(): void
    {
        $stripeClient = new ScriptedSetupClient();
        $stripe = new StripeGuide($stripeClient, new RequiredSettings(), 'https://api.stripe.com/');
        $this->assertSame('stripe', $stripe->id());
        $this->assertStringContainsString('/webhooks/stripe', $stripe->instructions());
        $this->assertStringContainsString('financial_connections.account.refreshed_balance', $stripe->instructions());
        $this->assertStringContainsString('financial_connections.account.disconnected', $stripe->instructions());
        $this->assertFalse($stripe->fields()[0]->hidden() === false);
        $this->assertFalse($stripe->fields()[1]->hidden());
        $this->assertSame('Stripe secret key', $stripe->fields()[0]->label());
        $this->assertSame('STRIPE_SECRET_KEY', $stripe->fields()[0]->key());
        $this->assertFalse($stripe->verify([]));
        $this->assertFalse($stripe->verify(['STRIPE_SECRET_KEY' => ' ', 'STRIPE_PUBLISHABLE_KEY' => 'pk', 'STRIPE_WEBHOOK_SECRET' => 'wh']));
        $this->assertTrue($stripe->verify(['STRIPE_SECRET_KEY' => 'sk', 'STRIPE_PUBLISHABLE_KEY' => 'pk', 'STRIPE_WEBHOOK_SECRET' => 'wh']));
        $this->assertSame('https://api.stripe.com/v1/account', $stripeClient->calls[0][1]);
        $this->assertSame('Stripe rejected the credentials.', $stripe->failure());

        $plaidClient = new ScriptedSetupClient();
        $plaid = new PlaidGuide($plaidClient, new RequiredSettings(), 'https://sandbox.plaid.com/');
        $this->assertSame('plaid', $plaid->id());
        $this->assertStringContainsString('/webhooks/plaid', $plaid->instructions());
        $this->assertStringContainsString('SYNC_UPDATES_AVAILABLE', $plaid->instructions());
        $this->assertFalse($plaid->verify(['PLAID_CLIENT_ID' => '', 'PLAID_SECRET' => 'secret']));
        $this->assertTrue($plaid->verify(['PLAID_CLIENT_ID' => 'id', 'PLAID_SECRET' => 'secret']));
        $this->assertSame('https://sandbox.plaid.com/institutions/get', $plaidClient->calls[0][1]);
        $this->assertSame('Plaid rejected the credentials.', $plaid->failure());

        $teller = new TellerGuide(new ScriptedSetupClient(readable: ['/cert' => false, '/key' => true]), new RequiredSettings());
        $this->assertSame('teller', $teller->id());
        $this->assertStringContainsString('does not submit KYB', $teller->instructions());
        $this->assertFalse($teller->fields()[3]->hidden() === false);
        $this->assertFalse($teller->verify([
            'TELLER_APPLICATION_ID' => 'app',
            'TELLER_CERT_PATH' => '/cert',
            'TELLER_KEY_PATH' => '/key',
            'TELLER_WEBHOOK_SECRET' => 'wh',
        ]));
        $this->assertFalse($teller->verify(['TELLER_APPLICATION_ID' => '', 'TELLER_CERT_PATH' => '/cert', 'TELLER_KEY_PATH' => '/key', 'TELLER_WEBHOOK_SECRET' => 'wh']));
        $this->assertSame('Teller rejected the credentials.', $teller->failure());

        $simple = new SimpleFinGuide(new RequiredSettings());
        $this->assertSame('simplefin', $simple->id());
        $this->assertSame('SIMPLEFIN_APP_ID', $simple->fields()[0]->key());
        $this->assertStringContainsString('SIMPLEFIN_APP_ID', $simple->instructions());
        $this->assertTrue($simple->verify(['SIMPLEFIN_APP_ID' => 'amtgard_denarius_dev', 'SIMPLEFIN_APP_TOKEN' => 'token']));
        $this->assertSame('SimpleFIN needs SIMPLEFIN_APP_ID and SIMPLEFIN_APP_TOKEN.', $simple->failure());
    }

    public function testHiddenLineAndEnvFragmentKeepSecretsOutOfTheTerminal(): void
    {
        $io = new ScriptedIo(["  secret value  "]);
        $line = (new HiddenLine($io))->read('Stripe secret key', true);
        $this->assertSame('secret value', $line);
        $this->assertSame(["Stripe secret key: ", "\n"], $io->written);
        $this->assertSame([true, false], $io->hidden);

        $visible = new ScriptedIo(['pk_test']);
        $this->assertSame('pk_test', (new HiddenLine($visible))->read('Stripe publishable key', false));
        $this->assertSame([], $visible->hidden);

        $path = $this->path();
        (new EnvFragment())->write($path, ['STRIPE_SECRET_KEY' => "say \"hi\"\\\nnext"]);
        $this->assertSame('STRIPE_SECRET_KEY="say \\"hi\\"\\\\next"' . "\n", (string) file_get_contents($path));
        $this->assertSame(0600, fileperms($path) & 0777);

        $missing = sys_get_temp_dir() . '/denarius-missing-' . uniqid() . '/provider.env';
        $this->assertThrows(\RuntimeException::class, fn () => (new EnvFragment())->write($missing, ['A' => 'b']));
    }

    public function testConsoleIoHidesInputOnlyOnATerminal(): void
    {
        $input = fopen('php://memory', 'w+');
        $output = fopen('php://memory', 'w+');
        $this->assertIsResource($input);
        $this->assertIsResource($output);
        fwrite($input, "line\n");
        rewind($input);
        $commands = [];
        $io = new ConsoleIo($input, $output, function (string $command) use (&$commands): void {
            $commands[] = $command;
        });
        $io->write('prompt');
        $this->assertSame('line', $io->read());
        $io->hide(true);
        rewind($output);
        $this->assertSame('prompt', stream_get_contents($output));
        $this->assertSame([], $commands);

        $empty = fopen('php://memory', 'w+');
        $this->assertIsResource($empty);
        $blank = new ConsoleIo($empty, $output, static function (): void {
        });
        $this->assertSame('', $blank->read());

        $tty = new ConsoleIo($input, $output, function (string $command) use (&$commands): void {
            $commands[] = $command;
        }, static fn (): bool => true);
        $tty->hide(true);
        $tty->hide(false);
        $this->assertSame(['stty -echo', 'stty echo'], $commands);

        $quiet = new ConsoleIo($input, $output, function (string $command) use (&$commands): void {
            $commands[] = $command;
        }, static fn (): bool => false);
        $quiet->hide(true);
        $this->assertSame(['stty -echo', 'stty echo'], $commands);
    }

    public function testCurlClientUsesAFetcherOrTheNetwork(): void
    {
        $client = new CurlSetupClient(static fn (): int => 204);
        $this->assertSame(204, $client->status('GET', 'http://127.0.0.1/account', [], ''));
        $zero = new CurlSetupClient(static fn (): int => 0);
        $this->assertSame(0, $zero->status('GET', 'http://127.0.0.1/account', [], ''));

        $file = $this->path();
        file_put_contents($file, 'cert');
        $live = new CurlSetupClient();
        $this->assertTrue($live->readable($file));
        $this->assertFalse($live->readable($file . '.missing'));

        [$base, $process] = $this->server();
        try {
            $this->assertSame(200, $live->status('GET', $base . '/ok', ['Accept: text/plain'], ''));
            $this->assertSame(401, $live->status('POST', $base . '/no', ['Content-Type: application/json'], '{"a":1}'));
            $this->assertSame(0, $live->status('GET', 'http://127.0.0.1:1/', [], ''));
        } finally {
            proc_terminate($process);
        }
    }

    private function wired(SetupClient $client, TextIo $io): ProviderSetup
    {
        $required = new RequiredSettings();

        return new ProviderSetup(
            [
                new StripeGuide($client, $required, 'https://api.stripe.test'),
                new PlaidGuide($client, $required, 'https://plaid.test'),
                new TellerGuide($client, $required),
                new SimpleFinGuide($required),
            ],
            new HiddenLine($io),
            new EnvFragment(),
            $io,
        );
    }

    private function path(): string
    {
        return sys_get_temp_dir() . '/denarius-provider-' . uniqid() . '.env';
    }

    /**
     * @return array{0: string, 1: resource}
     */
    private function server(): array
    {
        $root = sys_get_temp_dir() . '/denarius-setup';
        if (!is_dir($root)) {
            mkdir($root);
        }
        $router = $root . '/router.php';
        file_put_contents($router, <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/no') {
    http_response_code(401);
    echo 'no';
    return;
}
echo 'ok';
PHP);
        $port = 32000 + (getmypid() % 10000);
        $process = proc_open(
            sprintf('php -S 127.0.0.1:%d %s', $port, escapeshellarg($router)),
            [1 => ['file', $root . '/server.log', 'a'], 2 => ['file', $root . '/server.log', 'a']],
            $pipes,
        );
        $this->assertIsResource($process);
        $base = 'http://127.0.0.1:' . $port;
        $context = stream_context_create(['http' => ['timeout' => 1]]);
        for ($i = 0; $i < 20; $i++) {
            $probe = @file_get_contents($base . '/ok', false, $context);
            if ($probe === 'ok') {
                return [$base, $process];
            }
            usleep(50000);
        }
        $this->fail('Setup server did not start');
    }
}

final class ScriptedIo implements TextIo
{
    /** @var list<string> */
    public array $written = [];

    /** @var list<bool> */
    public array $hidden = [];

    private int $cursor = 0;

    /**
     * @param list<string> $lines
     */
    public function __construct(private array $lines)
    {
    }

    public function write(string $text): void
    {
        $this->written[] = $text;
    }

    public function read(): string
    {
        $line = $this->lines[$this->cursor] ?? '';
        $this->cursor++;

        return $line;
    }

    public function hide(bool $hide): void
    {
        $this->hidden[] = $hide;
    }
}

final class ScriptedSetupClient implements SetupClient
{
    /** @var list<array{0: string, 1: string, 2: list<string>, 3: string}> */
    public array $calls = [];

    /**
     * @param array<string, bool> $readable
     */
    public function __construct(private readonly int $status = 200, private readonly array $readable = [])
    {
    }

    public function status(string $method, string $url, array $headers, string $body): int
    {
        $this->calls[] = [$method, $url, $headers, $body];

        return $this->status;
    }

    public function readable(string $path): bool
    {
        return $this->readable[$path] ?? false;
    }
}
