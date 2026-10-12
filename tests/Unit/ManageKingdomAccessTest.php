<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

require_once __DIR__ . '/ApplicationTest.php';

use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Service\Access\PermissionService;
use Amtgard\Denarius\Utilities\Auth\BootstrapAdmins;
use Amtgard\Denarius\Utilities\Auth\ClaimOrn;
use Amtgard\Denarius\Utilities\Auth\DenariusAuthorizer;
use Amtgard\Denarius\Utilities\Http\TwigHtmlRenderer;
use Amtgard\IdpClient\OAuth\TokenSet;
use Amtgard\IdpClient\Resource\AuthenticatedSession;
use Amtgard\IdpClient\Resource\OrkProfile;
use Amtgard\IdpClient\Resource\UserProfile;
use Amtgard\IdpClient\Session\SessionAuthStore;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class ManageKingdomAccessTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testGuestRedirectsToLogin(): void
    {
        $kingdoms = new MemoryKingdoms();
        $twig = new TwigHtmlRenderer(new Environment(new ArrayLoader(['message.twig' => '{{ title }}'])));
        $access = Strategies::manageKingdomAccess(
            $twig,
            $kingdoms,
            new PermissionService(new FakePolicies([]), new ArrayStore(), new DenariusAuthorizer(), BootstrapAdmins::fromEnv(null)),
            new SessionAuthStore('empty'),
        );

        $response = $access->resolveManaged(new Response(), 'golden-plains');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaderLine('Location'));
    }

    public function testUnknownSlugReturnsNotFound(): void
    {
        $_SESSION['test_session'] = (new AuthenticatedSession(
            new TokenSet('a'),
            new UserProfile('9', 'person@example.com', 'jwt', OrkProfile::fromArray(['kingdom_id' => 4])),
        ))->toSessionArray();
        $kingdoms = new MemoryKingdoms();
        $twig = new TwigHtmlRenderer(new Environment(new ArrayLoader(['message.twig' => '{{ title }}'])));
        $permissions = new PermissionService(new FakePolicies([ClaimOrn::admin()]), new ArrayStore(), new DenariusAuthorizer(), BootstrapAdmins::fromEnv(null));
        $access = Strategies::manageKingdomAccess($twig, $kingdoms, $permissions, new SessionAuthStore('test_session'));

        $response = $access->resolveManaged(new Response(), 'missing');

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testNonManagerReturnsForbidden(): void
    {
        $_SESSION['test_session'] = (new AuthenticatedSession(
            new TokenSet('a'),
            new UserProfile('9', 'person@example.com', 'jwt', OrkProfile::fromArray(['kingdom_id' => 4])),
        ))->toSessionArray();
        $kingdoms = new MemoryKingdoms();
        $kingdoms->save(KingdomRecord::builder()->orkKingdomId(4)->slug('golden-plains')->build());
        $twig = new TwigHtmlRenderer(new Environment(new ArrayLoader(['message.twig' => '{{ title }}'])));
        $permissions = new PermissionService(new FakePolicies([]), new ArrayStore(), new DenariusAuthorizer(), BootstrapAdmins::fromEnv(null));
        $access = Strategies::manageKingdomAccess($twig, $kingdoms, $permissions, new SessionAuthStore('test_session'));

        $response = $access->resolveManaged(new Response(), 'golden-plains');

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testManagerReceivesKingdomRecord(): void
    {
        $_SESSION['test_session'] = (new AuthenticatedSession(
            new TokenSet('a'),
            new UserProfile('9', 'person@example.com', 'jwt', OrkProfile::fromArray(['kingdom_id' => 4])),
        ))->toSessionArray();
        $kingdoms = new MemoryKingdoms();
        $kingdoms->save(KingdomRecord::builder()->orkKingdomId(4)->slug('golden-plains')->name('Golden Plains')->build());
        $twig = new TwigHtmlRenderer(new Environment(new ArrayLoader(['message.twig' => '{{ title }}'])));
        $permissions = new PermissionService(new FakePolicies([ClaimOrn::admin()]), new ArrayStore(), new DenariusAuthorizer(), BootstrapAdmins::fromEnv(null));
        $access = Strategies::manageKingdomAccess($twig, $kingdoms, $permissions, new SessionAuthStore('test_session'));

        $resolved = $access->resolveManaged(new Response(), 'golden-plains');

        $this->assertInstanceOf(KingdomRecord::class, $resolved);
        $this->assertSame('golden-plains', $resolved->getSlug());
    }
}
