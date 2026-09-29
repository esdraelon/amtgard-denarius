<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit\Config;

use Amtgard\Denarius\Tests\Support\MethodLogRecorder;
use Amtgard\Denarius\Tests\Support\RecordingMethodLog;
use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Amtgard\Denarius\Utilities\Log\MethodLog;
use DI\Bridge\Slim\Bridge;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Interfaces\RouteInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Throwable;

/**
 * Boots like {@see public/index.php}: bootstrap, DenariusLog install, middleware, routes.
 */
final class AppBootstrapWiringTest extends TestCase
{
    private ContainerInterface $container;

    private ?MethodLog $restoredLogger = null;

    protected function setUp(): void
    {
        $this->applyPhpUnitEnvironmentOverrides();
        $this->ensureIdpTestEnvironment();
        $_ENV['SESSION_REDIS_HOST'] = '';

        $this->restoredLogger = MethodLogRecorder::active();
        $this->container = require dirname(__DIR__, 3) . '/config/bootstrap.php';
        DenariusLog::install($this->container->get(MethodLog::class));

        $this->applyPhpUnitEnvironmentOverrides();
        $this->ensureIdpTestEnvironment();
        $_ENV['SESSION_REDIS_HOST'] = '';
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];

        if ($this->restoredLogger instanceof RecordingMethodLog) {
            DenariusLog::install($this->restoredLogger);
        }

        parent::tearDown();
    }

    public function testEveryRegisteredRouteResolvesCallable(): void
    {
        $app = $this->bootstrappedApp();
        $routes = $app->getRouteCollector()->getRoutes();
        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $this->assertRouteCallable($route);
        }
    }

    public function testGetHomeReturnsOk(): void
    {
        $app = $this->bootstrappedApp();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/');
        $response = $app->handle($request);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testPostWithoutCsrfReturnsForbidden(): void
    {
        $app = $this->bootstrappedApp();
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/grant')
            ->withParsedBody(['action' => 'grant-admin']);
        $response = $app->handle($request);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('Forbidden', (string) $response->getBody());
    }

    private function bootstrappedApp(): App
    {
        $app = Bridge::create($this->container);
        (require dirname(__DIR__, 3) . '/config/middleware.php')($app);
        (require dirname(__DIR__, 3) . '/config/routes.php')($app);

        return $app;
    }

    private function assertRouteCallable(RouteInterface $route): void
    {
        $callable = $route->getCallable();
        $this->assertNotNull($callable);

        if (! is_array($callable) || count($callable) !== 2) {
            return;
        }

        [$class, $method] = $callable;
        $this->assertIsString($class);
        $this->assertIsString($method);
        $controller = $this->resolveOrSkip($class);
        $this->assertTrue(method_exists($controller, $method));
    }

    private function resolveOrSkip(string $class): object
    {
        try {
            return $this->container->get($class);
        } catch (Throwable $e) {
            if ($this->causedByInfrastructure($e)) {
                $this->markTestSkipped('Infrastructure not reachable for bootstrap wiring: ' . $e->getMessage());
            }

            throw $e;
        }
    }

    private function causedByInfrastructure(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof \RedisException) {
                return true;
            }
            if ($current instanceof \Amtgard\IdpClient\Exception\IdpConfigurationException) {
                return true;
            }
            if ($current instanceof \PDOException && $this->isConnectionFailure($current)) {
                return true;
            }
        }

        return false;
    }

    private function isConnectionFailure(\PDOException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? '';
        if ($sqlState === '42S02') {
            return false;
        }

        $message = $e->getMessage();

        return str_contains($message, 'getaddrinfo')
            || str_contains($message, 'Connection refused')
            || str_contains($message, 'server has gone away')
            || str_contains($message, '[2002]');
    }

    private function ensureIdpTestEnvironment(): void
    {
        $defaults = [
            'IDP_BASE_URL' => 'https://idp.amtgard.com',
            'IDP_CLIENT_ID' => 'test-client',
            'IDP_CLIENT_SECRET' => 'test-secret',
            'IDP_REDIRECT_URI' => 'http://localhost:37180/oauth/callback',
            'IDP_IAM_SERVICE' => 'Denarius',
            'IDP_IAM_SERVICE_FORMAT' => '["Configuration","Kingdom"]',
        ];

        foreach ($defaults as $key => $value) {
            $current = $_ENV[$key] ?? getenv($key);
            if (! is_string($current) || $current === '') {
                $_ENV[$key] = $value;
            }
        }
    }

    private function applyPhpUnitEnvironmentOverrides(): void
    {
        foreach ([
            'APP_ENV',
            'APP_DEBUG',
            'APP_KEY',
            'DB_HOST',
            'DB_PORT',
            'DB_NAME',
            'DB_USER',
            'DB_PASS',
            'IDP_BASE_URL',
            'IDP_CLIENT_ID',
            'IDP_CLIENT_SECRET',
            'IDP_REDIRECT_URI',
            'IDP_IAM_SERVICE',
            'IDP_IAM_SERVICE_FORMAT',
            'REDIS_HOST',
            'REDIS_PORT',
            'REDIS_DB',
            'SESSION_REDIS_HOST',
            'SESSION_REDIS_PORT',
            'SESSION_REDIS_DB',
        ] as $key) {
            $value = getenv($key);
            if ($value !== false) {
                $_ENV[$key] = $value;
            }
        }
    }
}
