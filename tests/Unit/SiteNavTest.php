<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Service\Access\AccountNavBuilder;
use Amtgard\Denarius\Service\Access\PermissionService;
use Amtgard\Denarius\Service\Access\SiteNavBuilder;
use Amtgard\Denarius\Utilities\Auth\BootstrapAdmins;
use Amtgard\Denarius\Utilities\Auth\DenariusAuthorizer;
use Amtgard\IdpClient\Session\SessionAuthStore;
use Amtgard\PHPUnit\AmtgardTestCase;

final class SiteNavTest extends AmtgardTestCase
{
    public function testGuestNavIncludesSignIn(): void
    {
        class_exists(ApplicationTest::class);
        $permissions = new PermissionService(
            new FakePolicies([]),
            new \Amtgard\Denarius\Tests\Unit\ArrayStore(),
            new DenariusAuthorizer(),
            BootstrapAdmins::fromEnv(null),
        );
        $kingdoms = new MemoryKingdoms();
        $nav = new SiteNavBuilder(
            new SessionAuthStore(),
            new AccountNavBuilder($permissions, Strategies::managedKingdomResolver($kingdoms, new MemoryPrincipals())),
        );
        $labels = array_column($nav->links(), 'label');
        $this->assertContains('Home', $labels);
        $this->assertContains('Sign in', $labels);
    }
}
