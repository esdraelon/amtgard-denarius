<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit\Log;

use Amtgard\Denarius\Domain\Access\KingdomAccess;
use Amtgard\Denarius\Domain\Access\Viewer;
use Amtgard\Denarius\Domain\Access\Visibility;
use Amtgard\Denarius\Persistence\Record\PrincipalRecord;
use Amtgard\Denarius\Service\Access\AccountNavBuilder;
use Amtgard\Denarius\Service\Access\PermissionService;
use Amtgard\Denarius\Service\Access\PrincipalSync;
use Amtgard\Denarius\Tests\Support\MethodLogAssert;
use Amtgard\Denarius\Tests\Support\TracedMethodCatalog;
use Amtgard\Denarius\Tests\Unit\ApplicationTest;
use Amtgard\Denarius\Tests\Unit\ArrayStore;
use Amtgard\Denarius\Tests\Unit\FakePolicies;
use Amtgard\Denarius\Tests\Unit\MemoryKingdoms;
use Amtgard\Denarius\Tests\Unit\MemoryPrincipals;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Utilities\Auth\BootstrapAdmins;
use Amtgard\Denarius\Utilities\Auth\ClaimOrn;
use Amtgard\Denarius\Utilities\Auth\CurrentActor;
use Amtgard\Denarius\Utilities\Auth\DenariusAuthorizer;
use Amtgard\Denarius\Utilities\Auth\Impl\IdpPolicyGateway;
use Amtgard\IdpClient\ClientIam\Model\PolicyClaim;
use Amtgard\IdpClient\ClientIam\Model\PolicyClaimList;
use Amtgard\IdpClient\ClientIam\Model\ServiceFormatRequest;
use Amtgard\PHPUnit\AmtgardTestCase;

final class TracedAuthMethodsTest extends AmtgardTestCase
{
    protected function tearDown(): void
    {
        CurrentActor::reset();
        unset($_SESSION['test_session'], $_SESSION['_csrf']);
        parent::tearDown();
    }

    public function testEveryAuthAccessTraceSiteIsAsserted(): void
    {
        MethodLogAssert::resetTraces();
        class_exists(ApplicationTest::class);

        $adminOrn = ClaimOrn::admin();
        $manageOrn = ClaimOrn::manage(6);
        ClaimOrn::parse('not-an-orn');
        ClaimOrn::parse($adminOrn);

        $bootstrap = BootstrapAdmins::fromEnv('9,10');
        $bootstrap->contains('9');
        $bootstrap->contains('missing');

        $authorizer = new DenariusAuthorizer();
        $authorizer->isAdmin('9', [$adminOrn], $bootstrap);
        $authorizer->isAdmin('1', [$manageOrn], BootstrapAdmins::fromEnv(null));
        $authorizer->managedKingdomIds([$manageOrn, $adminOrn]);

        CurrentActor::set('42');
        CurrentActor::id();
        CurrentActor::editedById();
        CurrentActor::set('not-numeric');
        CurrentActor::editedById();
        CurrentActor::reset();

        $iam = new class {
            public bool $format = false;

            public function getServiceFormat(): void
            {
                if (! $this->format) {
                    throw new \RuntimeException('missing');
                }
            }

            public function createServiceFormat(ServiceFormatRequest $request): void
            {
                $this->format = $request->serviceFormat === ['Configuration', 'Kingdom'];
            }

            public function listPolicyClaims(string $id): PolicyClaimList
            {
                return new PolicyClaimList([new PolicyClaim('Denarius', ':0:0:', 'Denarius/Admin')]);
            }

            public function addPolicyClaimFromOrn(string $id, string $orn): void
            {
            }

            public function composeClaim(array $segments, string $resource): object
            {
                return (object) ['segments' => $segments, 'resource' => $resource];
            }

            public function deletePolicyClaim(string $id, object $claim): void
            {
            }
        };
        $gateway = new IdpPolicyGateway($iam);
        $gateway->ensureFormat();
        $gateway->ensureFormat();
        $gateway->listOrns('1');
        $gateway->grant('1', $adminOrn);
        $gateway->revoke('1', $manageOrn);

        $cache = new ArrayStore();
        $permissions = new PermissionService(
            new FakePolicies([$adminOrn, $manageOrn]),
            $cache,
            $authorizer,
            $bootstrap,
        );
        $permissions->orns('9');
        $permissions->orns('9');
        $permissions->forget('9');
        $permissions->isAdmin('9');
        $permissions->managedKingdomIds('9');

        $kingdoms = new MemoryKingdoms();
        $kingdoms->save(KingdomRecord::builder()->orkKingdomId(6)->name('Iron Mountains')->slug('iron-mountains')->build());
        $nav = new AccountNavBuilder($permissions, $kingdoms);
        $composed = $nav->actionsFor('9');
        $this->assertCount(2, $composed);

        $principals = new MemoryPrincipals();
        $sync = new PrincipalSync($principals);
        $sync->upsert('9', 'person@example.com', 4, 'Golden Plains');
        $principals->save(PrincipalRecord::builder()->idpUserId('9')->email('person@example.com')->build());
        $sync->upsert('9', 'person@example.com', 5, 'Other');

        Visibility::fromStored('public');
        Visibility::fromStored('registered');
        Visibility::fromStored('kingdom_only');
        Visibility::fromStored('unknown');

        $viewer = new Viewer('9', 4);
        new Viewer('anon', null);

        $access = KingdomAccess::standard();
        $access->decide(Visibility::Public, null, 4);
        $access->decide(Visibility::Registered, null, 4);
        $access->decide(Visibility::Registered, $viewer, 4);
        $access->decide(Visibility::KingdomOnly, null, 4);
        $access->decide(Visibility::KingdomOnly, $viewer, 4);
        $access->decide(Visibility::KingdomOnly, new Viewer('9', 99), 4);

        $scope = $this->methodsInScope();
        $this->assertCount(44, $scope);
        foreach ($scope as $method) {
            if (str_ends_with($method, '::__construct')) {
                MethodLogAssert::assertConstructorEntered($method);
            } else {
                MethodLogAssert::assertTraced($method);
            }
            $this->addToAssertionCount(1);
        }
    }

    /**
     * @return list<string>
     */
    private function methodsInScope(): array
    {
        $catalog = TracedMethodCatalog::forProject();
        $scoped = [];
        foreach ($catalog->all() as $method) {
            if (str_contains($method, '\\Utilities\\Auth\\')
                || str_contains($method, '\\Domain\\Access\\')
                || str_contains($method, '\\Service\\Access\\')) {
                $scoped[] = $method;
            }
        }

        return $scoped;
    }
}
