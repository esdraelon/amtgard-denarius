<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit\Log;

use Amtgard\Denarius\Controller\AdminController;
use Amtgard\Denarius\Persistence\Record\PrincipalRecord;
use Amtgard\Denarius\Service\Access\PermissionService;
use Amtgard\Denarius\Service\Access\PrincipalSync;
use Amtgard\Denarius\Service\Admin\AdminGrantTargetResolver;
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
use Amtgard\Denarius\Utilities\Http\IdpUserDirectory;
use Amtgard\Denarius\Utilities\Http\TwigHtmlRenderer;
use Amtgard\Denarius\Utilities\Log\BranchLogLevel;
use Amtgard\IdpClient\Config\IdpClientEnvironmentFactory;
use Amtgard\IdpClient\OAuth\TokenSet;
use Amtgard\IdpClient\Resource\AuthenticatedSession;
use Amtgard\IdpClient\Resource\OrkProfile;
use Amtgard\IdpClient\Resource\UserProfile;
use Amtgard\IdpClient\Session\SessionAuthStore;
use Amtgard\PHPUnit\AmtgardTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as SlimResponse;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class GrantTargetLoggingTest extends AmtgardTestCase
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

    public function testIdpUserDirectoryLogsHttpLookupFailure(): void
    {
        $psr17 = new Psr17Factory();
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(404, [], '{"message":"404 Not Found"}');
            }
        };
        $directory = new IdpUserDirectory(
            IdpClientEnvironmentFactory::fromEnvVars([
                'IDP_BASE_URL' => 'https://idp.example.test',
                'IDP_CLIENT_ID' => 'denarius_test',
                'IDP_CLIENT_SECRET' => 'secret',
                'IDP_REDIRECT_URI' => 'https://denarius.example.test/oauth/callback',
            ]),
            $client,
            $psr17,
        );

        $lookup = $directory->lookupByEmail('megiddo@esdraelon.com');
        $this->assertFalse($lookup->isResolved());
        MethodLogAssert::assertBranchLogged(
            BranchLogLevel::Warn,
            'idp_user_directory_lookup_failed',
            'Amtgard\\Denarius\\Utilities\\Http\\IdpUserDirectory::lookupByEmail',
        );
    }

    public function testAdminGrantLogsResolveFailureForLegacyPrincipal(): void
    {
        $twig = new TwigHtmlRenderer(new Environment(new ArrayLoader(['message.twig' => '{{ title }}'])));
        $principals = new MemoryPrincipals();
        $principals->save(PrincipalRecord::builder()->idpUserId('31786326')->email('megiddo@esdraelon.com')->build());
        $grantTargets = new AdminGrantTargetResolver(
            new IdpUserDirectory(
                IdpClientEnvironmentFactory::fromEnvVars([
                    'IDP_BASE_URL' => 'https://idp.example.test',
                    'IDP_CLIENT_ID' => 'denarius_test',
                    'IDP_CLIENT_SECRET' => 'secret',
                    'IDP_REDIRECT_URI' => 'https://denarius.example.test/oauth/callback',
                ]),
                new class implements ClientInterface {
                    public function sendRequest(RequestInterface $request): ResponseInterface
                    {
                        return new Response(404, [], '{"message":"404 Not Found"}');
                    }
                },
                new Psr17Factory(),
            ),
            $principals,
            new PrincipalSync($principals),
        );
        $_SESSION['test_session'] = (new AuthenticatedSession(
            new TokenSet('a'),
            new UserProfile('9', 'admin@example.com', 'jwt', OrkProfile::fromArray(['kingdom_id' => 4, 'kingdom_name' => 'Golden Plains'])),
        ))->toSessionArray();
        $auth = new SessionAuthStore('test_session');
        $permissions = new PermissionService(
            new FakePolicies([ClaimOrn::admin()]),
            new ArrayStore(),
            new DenariusAuthorizer(),
            BootstrapAdmins::fromEnv(null),
        );
        $kingdoms = new MemoryKingdoms();
        $admin = new AdminController(
            $auth,
            $permissions,
            $principals,
            $kingdoms,
            new FakePolicies([]),
            new MemoryGrants(),
            $twig,
            Strategies::admin(),
            Strategies::orkKingdoms($kingdoms, $principals),
            $grantTargets,
            Strategies::grantedRoles(new MemoryGrants(), $principals, $kingdoms),
        );
        $_SESSION['_csrf'] = 'token';
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/grant')->withParsedBody([
            'csrf' => 'token',
            'target_email' => 'megiddo@esdraelon.com',
            'idp_user_id' => '31786326',
            'action' => 'grant-manager',
            'ork_kingdom_id' => '4',
            'kingdom_name' => 'Celestial Kingdom',
        ]);
        $response = $admin->grant($request, new SlimResponse());
        $this->assertSame(400, $response->getStatusCode());
        MethodLogAssert::assertBranchLogged(
            BranchLogLevel::Warn,
            'grant_target_resolve_failed',
            'Amtgard\\Denarius\\Controller\\AdminController::grant',
        );
    }
}
