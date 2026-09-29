<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit\Config;

use Amtgard\Denarius\Controller\AdminController;
use Amtgard\Denarius\Controller\HomeController;
use Amtgard\Denarius\Controller\KingdomPageController;
use Amtgard\Denarius\Controller\ManagerController;
use Amtgard\Denarius\Controller\WebhookController;
use Amtgard\Denarius\Domain\Access\KingdomAccess;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\Registry\LedgerProviderRegistry;
use Amtgard\Denarius\Persistence\Repository\Kingdom\KingdomRepositoryInterface;
use Amtgard\Denarius\Service\Access\PermissionService;
use Amtgard\Denarius\Service\Enrollment\EnrollmentService;
use Amtgard\Denarius\Service\Ledger\ProviderWebhookHandler;
use Amtgard\Denarius\Utilities\Log\CorrelationMiddleware;
use Amtgard\Denarius\Utilities\Log\MethodLog;
use Amtgard\Denarius\Utilities\Log\StderrMethodLog;
use Amtgard\Denarius\Worker\LedgerWorker;
use Amtgard\IdpClient\Client\IdpClient;
use Amtgard\IdpClient\Exception\IdpConfigurationException;
use Amtgard\IdpClient\Slim\IdpAuthController;
use Amtgard\IdpClient\Session\SessionAuthStore;
use DI\Bridge\Slim\Bridge;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RedisException;
use Slim\App;
use Throwable;

/**
 * Integration-shaped wiring test: bootstraps config/container.php and config/routes.php like production.
 */
final class ContainerResolutionOrderTest extends TestCase
{
    private ContainerInterface $container;

    protected function setUp(): void
    {
        $this->applyPhpUnitEnvironmentOverrides();
        $this->ensureIdpTestEnvironment();
        $_ENV['SESSION_REDIS_HOST'] = '';

        $this->container = require dirname(__DIR__, 3) . '/config/bootstrap.php';

        $this->applyPhpUnitEnvironmentOverrides();
        $this->ensureIdpTestEnvironment();
        $_ENV['SESSION_REDIS_HOST'] = '';
    }

    public function testCoreServicesResolveFromBootstrappedContainer(): void
    {
        $this->assertInstanceOf(MethodLog::class, $this->resolveOrSkip(MethodLog::class));
        $this->assertInstanceOf(StderrMethodLog::class, $this->resolveOrSkip(StderrMethodLog::class));
        $this->assertInstanceOf(CorrelationMiddleware::class, $this->resolveOrSkip(CorrelationMiddleware::class));
        $this->assertInstanceOf(SessionAuthStore::class, $this->resolveOrSkip(SessionAuthStore::class));
        $this->assertInstanceOf(IdpClient::class, $this->resolveOrSkip(IdpClient::class));
        $this->assertInstanceOf(KingdomAccess::class, $this->resolveOrSkip(KingdomAccess::class));
        $this->assertInstanceOf(LedgerProviderRegistry::class, $this->resolveOrSkip(LedgerProviderRegistry::class));
        $this->assertInstanceOf(ProviderWebhookHandler::class, $this->resolveOrSkip(ProviderWebhookHandler::class));
        $this->assertInstanceOf(EnrollmentService::class, $this->resolveOrSkip(EnrollmentService::class));
    }

    public function testOrmBackedRepositoriesResolveWhenDatabaseIsReachable(): void
    {
        $repository = $this->resolveOrSkip(KingdomRepositoryInterface::class);
        $this->assertInstanceOf(KingdomRepositoryInterface::class, $repository);
    }

    public function testControllersResolveWhenDependenciesAreReachable(): void
    {
        $this->assertInstanceOf(HomeController::class, $this->resolveOrSkip(HomeController::class));
        $this->assertInstanceOf(WebhookController::class, $this->resolveOrSkip(WebhookController::class));
        $this->assertInstanceOf(KingdomPageController::class, $this->resolveOrSkip(KingdomPageController::class));
        $this->assertInstanceOf(AdminController::class, $this->resolveOrSkip(AdminController::class));
        $this->assertInstanceOf(ManagerController::class, $this->resolveOrSkip(ManagerController::class));
    }

    public function testNamedRoutesMatchContainerControllers(): void
    {
        $app = $this->slimAppWithRoutes();
        $collector = $app->getRouteCollector();

        $named = [
            'home' => [HomeController::class, 'home'],
            'version' => [HomeController::class, 'version'],
            'auth.login' => [IdpAuthController::class, 'login'],
            'auth.callback' => [IdpAuthController::class, 'callback'],
            'auth.logout' => [IdpAuthController::class, 'logout'],
        ];

        foreach ($named as $routeName => [$class, $method]) {
            $callable = $collector->getNamedRoute($routeName)->getCallable();
            $this->assertSame([$class, $method], $callable);

            $controller = $this->resolveOrSkip($class);
            $this->assertTrue(method_exists($controller, $method));
        }
    }

    public function testAppBindingLoadsRoutesAndResolvesAuthController(): void
    {
        $app = $this->resolveOrSkip(App::class);
        $this->assertInstanceOf(App::class, $app);

        $auth = $this->resolveOrSkip(IdpAuthController::class);
        $this->assertInstanceOf(IdpAuthController::class, $auth);

        $this->assertNotNull($app->getRouteCollector()->getNamedRoute('home'));
    }

    public function testWorkerAndPermissionGraphResolveWhenInfrastructureIsReachable(): void
    {
        $this->assertInstanceOf(PermissionService::class, $this->resolveOrSkip(PermissionService::class));
        $this->assertInstanceOf(LedgerWorker::class, $this->resolveOrSkip(LedgerWorker::class));
    }

    private function slimAppWithRoutes(): App
    {
        $app = Bridge::create($this->container);
        (require dirname(__DIR__, 3) . '/config/routes.php')($app);

        return $app;
    }

    private function resolveOrSkip(string $class): object
    {
        try {
            return $this->container->get($class);
        } catch (Throwable $e) {
            if ($this->causedByInfrastructure($e)) {
                $this->markTestSkipped('Infrastructure not reachable for container integration: ' . $e->getMessage());
            }

            throw $e;
        }
    }

    private function causedByInfrastructure(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof RedisException) {
                return true;
            }
            if ($current instanceof IdpConfigurationException) {
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
            if (!is_string($current) || $current === '') {
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
