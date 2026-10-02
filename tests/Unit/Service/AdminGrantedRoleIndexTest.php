<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit\Service;

use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Persistence\Record\PrincipalRecord;
use Amtgard\Denarius\Persistence\Record\RoleGrantRecord;
use Amtgard\Denarius\Service\Admin\AdminGrantedRoleIndex;
use Amtgard\Denarius\Tests\Unit\ApplicationTest;
use Amtgard\Denarius\Tests\Unit\MemoryGrants;
use Amtgard\Denarius\Tests\Unit\MemoryKingdoms;
use Amtgard\Denarius\Tests\Unit\MemoryPrincipals;
use Amtgard\Denarius\Tests\Unit\Strategies;
use Amtgard\Denarius\Utilities\Auth\ClaimOrn;
use Amtgard\PHPUnit\AmtgardTestCase;

final class AdminGrantedRoleIndexTest extends AmtgardTestCase
{
    protected function setUp(): void
    {
        class_exists(ApplicationTest::class);
        parent::setUp();
    }

    public function testSearchBuildsEffectiveGrantsAndFilters(): void
    {
        $grants = new MemoryGrants();
        $grants->append(RoleGrantRecord::builder()
            ->actorIdpUserId('1')
            ->targetIdpUserId('u-admin')
            ->action('grant')
            ->resource(ClaimOrn::ADMIN)
            ->createdAt('2026-09-01T10:00:00+00:00')
            ->build());
        $grants->append(RoleGrantRecord::builder()
            ->actorIdpUserId('1')
            ->targetIdpUserId('u-manager')
            ->action('grant')
            ->resource(ClaimOrn::MANAGE)
            ->orkKingdomId(14)
            ->createdAt('2026-09-02T10:00:00+00:00')
            ->build());
        $grants->append(RoleGrantRecord::builder()
            ->actorIdpUserId('1')
            ->targetIdpUserId('u-manager')
            ->action('revoke')
            ->resource(ClaimOrn::MANAGE)
            ->orkKingdomId(14)
            ->createdAt('2026-09-03T10:00:00+00:00')
            ->build());
        $grants->append(RoleGrantRecord::builder()
            ->actorIdpUserId('1')
            ->targetIdpUserId('u-manager')
            ->action('grant')
            ->resource(ClaimOrn::MANAGE)
            ->orkKingdomId(4)
            ->createdAt('2026-09-04T10:00:00+00:00')
            ->build());

        $principals = new MemoryPrincipals();
        $principals->save(PrincipalRecord::builder()->idpUserId('u-admin')->email('admin@example.com')->build());
        $principals->save(PrincipalRecord::builder()->idpUserId('u-manager')->email('megiddo@esdraelon.com')->build());

        $kingdoms = new MemoryKingdoms();
        $kingdoms->save(KingdomRecord::builder()->orkKingdomId(4)->name('Golden Plains')->slug('golden-plains')->build());

        $index = new AdminGrantedRoleIndex($grants, $principals, Strategies::orkKingdoms($kingdoms, $principals));

        $all = $index->search(null, null);
        $this->assertCount(2, $all);

        $byEmail = $index->search('megiddo', null);
        $this->assertCount(1, $byEmail);
        $this->assertSame('megiddo@esdraelon.com', $byEmail[0]['email']);
        $this->assertSame('Kingdom manager', $byEmail[0]['permission']);
        $this->assertSame('Golden Plains', $byEmail[0]['kingdomName']);

        $byKingdom = $index->search(null, 'golden');
        $this->assertCount(1, $byKingdom);
        $this->assertSame('Golden Plains', $byKingdom[0]['kingdomName']);

        $adminOnly = $index->search('admin@', 'golden');
        $this->assertSame([], $adminOnly);
    }
}
