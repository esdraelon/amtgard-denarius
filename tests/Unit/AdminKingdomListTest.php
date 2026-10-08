<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Controller\AdminController;
use Amtgard\Denarius\Utilities\Auth\BootstrapAdmins;
use Amtgard\Denarius\Utilities\Auth\ClaimOrn;
use Amtgard\Denarius\Utilities\Auth\DenariusAuthorizer;
use Amtgard\Denarius\Utilities\Http\OrkKingdomCacheWriter;
use Amtgard\Denarius\Utilities\Http\OrkKingdomDirectory;
use Amtgard\Denarius\Utilities\Http\TwigHtmlRenderer;
use Amtgard\Denarius\Service\Access\PermissionService;
use Amtgard\Denarius\Tests\Support\StubOrkGetKingdomsGateway;
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

final class AdminKingdomListTest extends AmtgardTestCase
{
    public function testAdminKingdomsJsonSeedsFromBundledWhenOrkFetchFails(): void
    {
        $root = sys_get_temp_dir() . '/denarius-admin-kingdoms-' . uniqid();
        mkdir($root . '/data', 0775, true);
        copy(
            dirname(__DIR__, 2) . '/data/ork-kingdoms.bundled.json',
            $root . '/data/ork-kingdoms.bundled.json',
        );

        $kingdoms = new MemoryKingdoms();
        $principals = new MemoryPrincipals();
        $directory = new OrkKingdomDirectory(
            $root,
            'data/ork-kingdoms.json',
            $kingdoms,
            $principals,
            new StubOrkGetKingdomsGateway(null),
            new OrkKingdomCacheWriter(),
        );

        $auth = new SessionAuthStore('admin_kingdom_test');
        $_SESSION['admin_kingdom_test'] = (new AuthenticatedSession(
            new TokenSet('a'),
            new UserProfile('3', 'admin@example.com', 'jwt', OrkProfile::fromArray([])),
        ))->toSessionArray();

        $permissions = new PermissionService(
            new FakePolicies([ClaimOrn::admin()]),
            new ArrayStore(),
            new DenariusAuthorizer(),
            BootstrapAdmins::fromEnv(null),
        );
        $twig = new TwigHtmlRenderer(new Environment(new ArrayLoader(['admin.twig' => 'ok'])));
        $admin = new AdminController(
            $auth,
            $permissions,
            $principals,
            $kingdoms,
            new FakePolicies([]),
            new MemoryGrants(),
            $twig,
            Strategies::admin(),
            $directory,
            Strategies::grantTargets($principals),
            Strategies::grantedRoles(new MemoryGrants(), $principals, $kingdoms),
            Strategies::principalSuggester($principals),
        );

        $response = $admin->kingdoms(
            (new ServerRequestFactory())->createServerRequest('GET', '/admin/kingdoms'),
            new Response(),
        );
        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(27, $payload['kingdoms']);
        $this->assertFileExists($root . '/data/ork-kingdoms.json');
    }

    public function testSyncKingdomsImportsBrowserOrkJson(): void
    {
        $root = sys_get_temp_dir() . '/denarius-admin-sync-' . uniqid();
        mkdir($root . '/data', 0775, true);

        $directory = new OrkKingdomDirectory(
            $root,
            'data/ork-kingdoms.json',
            new MemoryKingdoms(),
            new MemoryPrincipals(),
            new StubOrkGetKingdomsGateway(null),
            new OrkKingdomCacheWriter(),
        );

        $auth = new SessionAuthStore('admin_sync_test');
        $_SESSION['admin_sync_test'] = (new AuthenticatedSession(
            new TokenSet('a'),
            new UserProfile('3', 'admin@example.com', 'jwt', OrkProfile::fromArray([])),
        ))->toSessionArray();
        $_SESSION['_csrf'] = 'sync-token';

        $permissions = new PermissionService(
            new FakePolicies([ClaimOrn::admin()]),
            new ArrayStore(),
            new DenariusAuthorizer(),
            BootstrapAdmins::fromEnv(null),
        );
        $twig = new TwigHtmlRenderer(new Environment(new ArrayLoader(['admin.twig' => 'ok'])));
        $principals = new MemoryPrincipals();
        $admin = new AdminController(
            $auth,
            $permissions,
            $principals,
            new MemoryKingdoms(),
            new FakePolicies([]),
            new MemoryGrants(),
            $twig,
            Strategies::admin(),
            $directory,
            Strategies::grantTargets($principals),
            Strategies::grantedRoles(new MemoryGrants(), $principals, new MemoryKingdoms()),
            Strategies::principalSuggester($principals),
        );

        $orkJson = json_encode([
            'Status' => ['Status' => 0],
            'Kingdoms' => [
                ['KingdomId' => 12, 'KingdomName' => 'Emerald Hills'],
            ],
        ], JSON_THROW_ON_ERROR);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/admin/kingdoms/sync')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFor(json_encode(['csrf' => 'sync-token', 'ork_json' => $orkJson], JSON_THROW_ON_ERROR)));

        $response = $admin->syncKingdoms($request, new Response());
        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([['id' => 12, 'name' => 'Emerald Hills']], $payload['kingdoms']);
    }

    public function testSyncKingdomsRejectsBadCsrf(): void
    {
        $directory = new OrkKingdomDirectory(
            sys_get_temp_dir(),
            'missing/cache.json',
            new MemoryKingdoms(),
            new MemoryPrincipals(),
            new StubOrkGetKingdomsGateway(null),
            new OrkKingdomCacheWriter(),
        );
        $auth = new SessionAuthStore('admin_sync_csrf');
        $_SESSION['admin_sync_csrf'] = (new AuthenticatedSession(
            new TokenSet('a'),
            new UserProfile('3', 'admin@example.com', 'jwt', OrkProfile::fromArray([])),
        ))->toSessionArray();
        $permissions = new PermissionService(
            new FakePolicies([ClaimOrn::admin()]),
            new ArrayStore(),
            new DenariusAuthorizer(),
            BootstrapAdmins::fromEnv(null),
        );
        $principals = new MemoryPrincipals();
        $admin = new AdminController(
            $auth,
            $permissions,
            $principals,
            new MemoryKingdoms(),
            new FakePolicies([]),
            new MemoryGrants(),
            new TwigHtmlRenderer(new Environment(new ArrayLoader(['admin.twig' => 'ok']))),
            Strategies::admin(),
            $directory,
            Strategies::grantTargets($principals),
            Strategies::grantedRoles(new MemoryGrants(), $principals, new MemoryKingdoms()),
            Strategies::principalSuggester($principals),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/admin/kingdoms/sync')
            ->withBody($this->streamFor('{"csrf":"wrong","ork_json":"{}"}'));
        $response = $admin->syncKingdoms($request, new Response());
        $this->assertSame(403, $response->getStatusCode());
    }

    private function streamFor(string $contents): \Psr\Http\Message\StreamInterface
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);

        return new \Slim\Psr7\Stream($stream);
    }
}
