<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit\Log;

use Amtgard\Denarius\Controller\AdminController;
use Amtgard\Denarius\Service\Ledger\ProviderWebhookHandler;
use Amtgard\Denarius\Tests\Support\MethodLogAssert;
use Amtgard\Denarius\Tests\Support\RecordingMethodLog;
use Amtgard\Denarius\Tests\Unit\ApplicationTest;
use Amtgard\Denarius\Tests\Unit\ArrayStore;
use Amtgard\Denarius\Tests\Unit\FakePolicies;
use Amtgard\Denarius\Tests\Unit\MemoryGrants;
use Amtgard\Denarius\Tests\Unit\MemoryKingdoms;
use Amtgard\Denarius\Tests\Unit\MemoryPrincipals;
use Amtgard\Denarius\Tests\Unit\Strategies;
use Amtgard\Denarius\Utilities\Auth\BootstrapAdmins;
use Amtgard\Denarius\Utilities\Auth\ClaimOrn;
use Amtgard\Denarius\Utilities\Auth\DenariusAuthorizer;
use Amtgard\Denarius\Utilities\Http\PostCsrfMiddleware;
use Amtgard\Denarius\Utilities\Http\SyncPrincipalMiddleware;
use Amtgard\Denarius\Utilities\Http\TwigHtmlRenderer;
use Amtgard\Denarius\Utilities\Log\BranchLogLevel;
use Amtgard\Denarius\Utilities\Log\StderrMethodLog;
use Amtgard\Denarius\Service\Access\PermissionService;
use Amtgard\Denarius\Service\Access\PrincipalSync;
use Amtgard\Denarius\Service\Enrollment\EnrollmentService;
use Amtgard\Denarius\Tests\Unit\MemoryAccounts;
use Amtgard\Denarius\Tests\Unit\MemoryRefresh;
use Amtgard\Denarius\Tests\Unit\MemorySecrets;
use Amtgard\Denarius\Utilities\Security\TokenCipher;
use Amtgard\IdpClient\OAuth\TokenSet;
use Amtgard\IdpClient\Resource\AuthenticatedSession;
use Amtgard\IdpClient\Resource\OrkProfile;
use Amtgard\IdpClient\Resource\UserProfile;
use Amtgard\IdpClient\Session\SessionAuthStore;
use Amtgard\PHPUnit\AmtgardTestCase;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class BranchLoggingTest extends AmtgardTestCase
{
    private RecordingMethodLog $recorder;

    protected function setUp(): void
    {
        $active = \Amtgard\Denarius\Tests\Support\MethodLogRecorder::active();
        $this->assertInstanceOf(RecordingMethodLog::class, $active);
        $this->recorder = $active;
        MethodLogAssert::reset();
        class_exists(ApplicationTest::class);
        unset($_SESSION['_csrf'], $_SESSION['test_session']);
    }

    public function testPostCsrfMiddlewareLogsRejectBranch(): void
    {
        $_SESSION['_csrf'] = 'token';
        $middleware = new PostCsrfMiddleware(new ResponseFactory());
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        };
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/grant')
            ->withParsedBody(['csrf' => 'wrong']);
        $response = $middleware->process($request, $handler);
        $this->assertSame(403, $response->getStatusCode());
        MethodLogAssert::assertBranchLogged(
            BranchLogLevel::Warn,
            'csrf_reject',
            'Amtgard\\Denarius\\Utilities\\Http\\PostCsrfMiddleware::process',
        );
    }

    public function testSyncPrincipalLogsGuestAndSessionBranches(): void
    {
        $principals = new MemoryPrincipals();
        $sync = new PrincipalSync($principals);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        };
        $guest = new SyncPrincipalMiddleware(new SessionAuthStore('empty'), $sync);
        $guest->process((new ServerRequestFactory())->createServerRequest('GET', '/'), $handler);
        MethodLogAssert::assertBranchLogged(
            BranchLogLevel::Debug,
            'principal_sync_guest',
            'Amtgard\\Denarius\\Utilities\\Http\\SyncPrincipalMiddleware::process',
        );

        MethodLogAssert::reset();
        $auth = new SessionAuthStore('test_session');
        $_SESSION['test_session'] = (new AuthenticatedSession(
            new TokenSet('a'),
            new UserProfile('9', 'person@example.com', 'jwt', OrkProfile::fromArray(['kingdom_id' => 4, 'kingdom_name' => 'Golden Plains'])),
        ))->toSessionArray();
        $session = new SyncPrincipalMiddleware($auth, $sync);
        $session->process((new ServerRequestFactory())->createServerRequest('GET', '/'), $handler);
        MethodLogAssert::assertBranchLogged(
            BranchLogLevel::Debug,
            'principal_sync_session',
            'Amtgard\\Denarius\\Utilities\\Http\\SyncPrincipalMiddleware::process',
        );
    }

    public function testWebhookHandlerLogsAuthDeniedBranch(): void
    {
        $kingdoms = new MemoryKingdoms();
        $queue = new MemoryRefresh();
        $enrollment = new EnrollmentService($kingdoms, new MemorySecrets(), new MemoryAccounts(), Strategies::providers(Strategies::teller()), new TokenCipher('k'), $queue, Strategies::months(), Strategies::bankReset());
        $handler = new ProviderWebhookHandler(Strategies::providers(Strategies::teller()), $kingdoms, Strategies::events($queue, $enrollment));
        $this->assertFalse($handler->handle('teller', '{}', null, time()));
        MethodLogAssert::assertBranchLogged(
            BranchLogLevel::Warn,
            'webhook_auth_denied',
            'Amtgard\\Denarius\\Service\\Ledger\\ProviderWebhookHandler::handle',
        );
    }

    public function testAdminGuardLogsAuthBranches(): void
    {
        $twig = new TwigHtmlRenderer(new Environment(new ArrayLoader(['admin.twig' => 'a', 'message.twig' => '{{ title }}'])));
        $member = new PermissionService(new FakePolicies([]), new ArrayStore(), new DenariusAuthorizer(), BootstrapAdmins::fromEnv(null));
        $principals = new MemoryPrincipals();
        $kingdoms = new MemoryKingdoms();
        $orkKingdoms = Strategies::orkKingdoms($kingdoms, $principals);
        $grantTargets = Strategies::grantTargets($principals);
        $admin = new AdminController(
            new SessionAuthStore('empty'),
            $member,
            $principals,
            $kingdoms,
            new FakePolicies([]),
            new MemoryGrants(),
            $twig,
            Strategies::admin(),
            $orkKingdoms,
            $grantTargets,
            Strategies::grantedRoles(new MemoryGrants(), $principals, $kingdoms),
            Strategies::principalSuggester($principals),
        );
        $admin->index((new ServerRequestFactory())->createServerRequest('GET', '/admin'), new Response());
        MethodLogAssert::assertBranchLogged(
            BranchLogLevel::Info,
            'auth_login_required',
            'Amtgard\\Denarius\\Controller\\AdminController::guard',
        );

        MethodLogAssert::reset();
        $auth = new SessionAuthStore('test_session');
        $_SESSION['test_session'] = (new AuthenticatedSession(
            new TokenSet('a'),
            new UserProfile('9', 'person@example.com', 'jwt', null),
        ))->toSessionArray();
        $denied = new AdminController($auth, $member, $principals, $kingdoms, new FakePolicies([]), new MemoryGrants(), $twig, Strategies::admin(), $orkKingdoms, $grantTargets, Strategies::grantedRoles(new MemoryGrants(), $principals, $kingdoms), Strategies::principalSuggester($principals));
        $denied->index((new ServerRequestFactory())->createServerRequest('GET', '/admin'), new Response());
        MethodLogAssert::assertBranchLogged(
            BranchLogLevel::Warn,
            'auth_admin_denied',
            'Amtgard\\Denarius\\Controller\\AdminController::guard',
        );
    }

    public function testStderrEmitsInfoAndWarnBranchesWhenDebugOff(): void
    {
        $lines = [];
        $log = StderrMethodLog::withHandler($this->collectingHandler($lines), false);
        $log->branch(BranchLogLevel::Warn, 'csrf_reject', 'Amtgard\\Denarius\\Utilities\\Http\\PostCsrfMiddleware::process', ['path' => '/x']);
        $log->branch(BranchLogLevel::Debug, 'csrf_ok', 'Amtgard\\Denarius\\Utilities\\Http\\PostCsrfMiddleware::process', []);
        $this->assertCount(1, $lines);
        $payload = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('branch', $payload['event']);
        $this->assertSame('csrf_reject', $payload['branch']);
        $this->assertSame('http', $payload['channel']);
    }

    /** @param list<string> $lines */
    private function collectingHandler(array &$lines): AbstractProcessingHandler
    {
        return new class($lines) extends AbstractProcessingHandler {
            /** @param list<string> $lines */
            public function __construct(private array &$lines)
            {
                parent::__construct();
            }

            protected function write(LogRecord $record): void
            {
                $this->lines[] = $record->message;
            }
        };
    }
}
