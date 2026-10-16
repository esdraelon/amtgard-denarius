<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Utilities\Setup\HostEnv\HostEnvBootstrap;
use Amtgard\Denarius\Utilities\Setup\HostEnv\HostEnvRuntimeSecretGateway;
use Amtgard\Denarius\Utilities\Setup\HostEnv\InjectedSecretsLoader;
use Amtgard\HostEnvVault\RuntimeVaultClient;
use Amtgard\PHPUnit\AmtgardTestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
final class HostEnvBootstrapTest extends AmtgardTestCase
{
    private string $tmpdir;

    protected function setUp(): void
    {
        $this->tmpdir = sys_get_temp_dir() . '/denarius-hev-' . bin2hex(random_bytes(4));
        mkdir($this->tmpdir, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->unsetEnv([
            'HOST_ENV_SECRETS_DIR',
            'DENARIUS_HEV_CONNECT_SECRETS',
            'VAULT_ONLY_KEY',
            'ALREADY_SET',
            'TELLER_CERT_PATH',
            'RUNTIME_KEY',
        ]);
        $this->rmTree($this->tmpdir);
    }

    public function testInjectAppliesMissingKeysViaDotenvAndLogs(): void
    {
        $secretsDir = $this->tmpdir . '/web';
        mkdir($secretsDir . '/files', 0700, true);
        file_put_contents($secretsDir . '/secrets.env', "VAULT_ONLY_KEY=from-vault\n");
        file_put_contents($secretsDir . '/files/teller.pem', "pem-bytes\n");

        putenv('HOST_ENV_SECRETS_DIR=' . $secretsDir);
        $_ENV['HOST_ENV_SECRETS_DIR'] = $secretsDir;

        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);

        HostEnvBootstrap::builder()->build()->logger($logger)->applyFromProcessEnv();

        $this->assertSame('from-vault', $_ENV['VAULT_ONLY_KEY'] ?? '');
        $this->assertSame($secretsDir . '/files/teller.pem', $_ENV['TELLER_CERT_PATH'] ?? '');
        $this->assertTrue($handler->hasInfoThatContains('host_env_inject_applied'));
    }

    public function testInjectSkipLogsWhenSecretsEnvMissing(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);
        InjectedSecretsLoader::builder()->build()
            ->secretsDir($this->tmpdir . '/missing')
            ->logger($logger)
            ->applyMissing();

        $this->assertTrue($handler->hasDebugThatContains('host_env_inject_skip'));
    }

    public function testRuntimeGatewayUsesInjectFallbackForConnectProfile(): void
    {
        $connectDir = $this->tmpdir . '/connect';
        mkdir($connectDir, 0700, true);
        file_put_contents($connectDir . '/secrets.env', "RUNTIME_KEY=connect-inject\n");

        $gateway = HostEnvRuntimeSecretGateway::builder()->build()
            ->runtimeDir('')
            ->injectFallbackDirs(['denarius-connect' => $connectDir]);

        $this->assertSame('connect-inject', $gateway->fetchEnv('denarius-connect', 'RUNTIME_KEY'));
    }

    public function testRuntimeGatewayUsesSocketClientWhenConfigured(): void
    {
        $client = RuntimeVaultClient::builder()->build()->socketPath('/tmp/unused.sock');
        $client->transport(new FakeRuntimeTransport(['RUNTIME_KEY' => 'runtime-value']));

        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);
        $gateway = HostEnvRuntimeSecretGateway::builder()->build()
            ->runtimeDir('/run/host-env-vault')
            ->client($client)
            ->logger($logger);

        $this->assertSame('runtime-value', $gateway->fetchEnv('denarius-connect', 'RUNTIME_KEY'));
        $this->assertTrue($handler->hasDebugThatContains('host_env_runtime_fetch_ok'));
    }

    /**
     * @param list<string> $keys
     */
    private function unsetEnv(array $keys): void
    {
        foreach ($keys as $key) {
            putenv($key);
            unset($_ENV[$key]);
        }
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->rmTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
