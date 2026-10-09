<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Controller\HomeController;
use Amtgard\Denarius\Service\Access\AccountNavBuilder;
use Amtgard\Denarius\Utilities\Http\TwigHtmlRenderer;
use Amtgard\Denarius\Utilities\Http\Twig\SiteNavTwigExtension;
use Amtgard\Denarius\Service\Access\SiteNavBuilder;
use Amtgard\Denarius\Utilities\Auth\BootstrapAdmins;
use Amtgard\Denarius\Utilities\Auth\DenariusAuthorizer;
use Amtgard\Denarius\Service\Access\PermissionService;
use Amtgard\Denarius\Domain\Access\KingdomAccess;
use Amtgard\IdpClient\OAuth\TokenSet;
use Amtgard\IdpClient\Resource\AuthenticatedSession;
use Amtgard\IdpClient\Resource\OrkProfile;
use Amtgard\IdpClient\Resource\UserProfile;
use Amtgard\IdpClient\Session\SessionAuthStore;
use Amtgard\PHPUnit\AmtgardTestCase;
use Slim\Psr7\Response;
use Slim\Psr7\Factory\ServerRequestFactory;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class HomeKingdomNavigationTest extends AmtgardTestCase
{
    public function testHomeRendersKingdomDirectoryAndSiteNav(): void
    {
        $root = dirname(__DIR__, 2);
        $loader = new FilesystemLoader($root . '/templates');
        $twig = new Environment($loader, ['cache' => false]);
        $auth = new SessionAuthStore('home_nav_test');
        $permissions = new PermissionService(new FakePolicies([]), new ArrayStore(), new DenariusAuthorizer(), BootstrapAdmins::fromEnv(null));
        $kingdoms = new MemoryKingdoms();
        $principals = new MemoryPrincipals();
        $accountNav = new AccountNavBuilder($permissions, Strategies::managedKingdomResolver($kingdoms, $principals));
        $twig->addExtension(new SiteNavTwigExtension(new SiteNavBuilder($auth, $accountNav)));

        $_SESSION['home_nav_test'] = (new AuthenticatedSession(
            new TokenSet('a'),
            new UserProfile('9', 'person@example.com', 'jwt', OrkProfile::fromArray([])),
        ))->toSessionArray();

        $home = new HomeController(
            new TwigHtmlRenderer($twig),
            $auth,
            $accountNav,
            $kingdoms,
            KingdomAccess::standard(),
            Strategies::orkKingdoms($kingdoms, $principals),
            $root,
        );

        $response = $home->home((new ServerRequestFactory())->createServerRequest('GET', '/'), new Response());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('Kingdom statements', $body);
        $this->assertStringContainsString('The Kingdom of the Golden Plains', $body);
        $this->assertStringContainsString('aria-label="Site"', $body);
        $this->assertStringContainsString('nav-link', $body);
    }
}
