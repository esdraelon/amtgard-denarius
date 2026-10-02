<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit\Service\Access;

use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Service\Access\AccountNavBuilder;
use Amtgard\Denarius\Tests\Unit\ApplicationTest;
use Amtgard\Denarius\Tests\Unit\ArrayStore;
use Amtgard\Denarius\Tests\Unit\FakePolicies;
use Amtgard\Denarius\Tests\Unit\MemoryKingdoms;
use Amtgard\Denarius\Utilities\Auth\BootstrapAdmins;
use Amtgard\Denarius\Utilities\Auth\ClaimOrn;
use Amtgard\Denarius\Utilities\Auth\DenariusAuthorizer;
use Amtgard\Denarius\Service\Access\PermissionService;
use Amtgard\PHPUnit\AmtgardTestCase;

final class AccountNavBuilderTest extends AmtgardTestCase
{
    protected function setUp(): void
    {
        class_exists(ApplicationTest::class);
        parent::setUp();
    }

    public function testActionsComposeAdminAndMultipleKingdomManagers(): void
    {
        $kingdoms = new MemoryKingdoms();
        $kingdoms->save(KingdomRecord::builder()->orkKingdomId(4)->name('Golden Plains')->slug('golden-plains')->build());
        $kingdoms->save(KingdomRecord::builder()->orkKingdomId(14)->name('The Celestial Kingdom')->slug('the-celestial-kingdom')->build());

        $permissions = new PermissionService(
            new FakePolicies([ClaimOrn::admin(), ClaimOrn::manage(4), ClaimOrn::manage(14)]),
            new ArrayStore(),
            new DenariusAuthorizer(),
            BootstrapAdmins::fromEnv(null),
        );
        $nav = new AccountNavBuilder($permissions, $kingdoms);

        $actions = $nav->actionsFor('user-1');
        $this->assertCount(3, $actions);
        $this->assertSame('Admin', $actions[0]['label']);
        $this->assertSame('/admin', $actions[0]['href']);
        $this->assertSame('Manage Golden Plains', $actions[1]['label']);
        $this->assertSame('/manage/golden-plains', $actions[1]['href']);
        $this->assertSame('Manage The Celestial Kingdom', $actions[2]['label']);
    }
}
