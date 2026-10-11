<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Controller\ManagerController;
use Amtgard\Denarius\Service\Enrollment\BankConnect;
use Amtgard\Denarius\Service\Enrollment\EnrollmentService;
use Amtgard\Denarius\Service\Enrollment\SimpleFinConnectSession;
use Amtgard\Denarius\Service\Access\PermissionService;
use Amtgard\Denarius\Utilities\Auth\BootstrapAdmins;
use Amtgard\Denarius\Utilities\Auth\ClaimOrn;
use Amtgard\Denarius\Utilities\Auth\DenariusAuthorizer;
use Amtgard\Denarius\Utilities\Http\TwigHtmlRenderer;
use Amtgard\Denarius\Utilities\Queue\KingdomRefresh\KingdomRefreshQueue;
use Amtgard\Denarius\Utilities\Security\TokenCipher;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\IdpClient\OAuth\TokenSet;
use Amtgard\IdpClient\Resource\AuthenticatedSession;
use Amtgard\IdpClient\Resource\OrkProfile;
use Amtgard\IdpClient\Resource\UserProfile;
use Amtgard\IdpClient\Session\SessionAuthStore;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class ManagerControllerCategorySearchTest extends TestCase
{
    private ManagerController $manager;

    protected function setUp(): void
    {
        $_SESSION['test_session'] = (new AuthenticatedSession(
            new TokenSet('a'),
            new UserProfile('9', 'person@example.com', 'jwt', OrkProfile::fromArray(['kingdom_id' => 4, 'kingdom_name' => 'Golden Plains'])),
        ))->toSessionArray();

        $kingdoms = new MemoryKingdoms();
        $kingdoms->save(KingdomRecord::builder()
            ->orkKingdomId(4)
            ->name('Golden Plains')
            ->slug('golden-plains')
            ->build());
        $accounts = new MemoryAccounts();
        $transactions = new MemoryTransactions();
        $queue = new class implements KingdomRefreshQueue {
            public function publishLedger(int $orkKingdomId): void
            {
            }
        };
        $permissions = new PermissionService(new FakePolicies([ClaimOrn::admin()]), new ArrayStore(), new DenariusAuthorizer(), BootstrapAdmins::fromEnv(null));
        $this->manager = new ManagerController(
            new SessionAuthStore('test_session'),
            $permissions,
            $kingdoms,
            $accounts,
            Strategies::kingdomSettings($kingdoms),
            new EnrollmentService(
                $kingdoms,
                new MemorySecrets(),
                $accounts,
                Strategies::providers(Strategies::teller()),
                new TokenCipher('k'),
                $queue,
                Strategies::months(),
                Strategies::bankReset(),
            ),
            $queue,
            new TwigHtmlRenderer(new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'))),
            new BankConnect(Strategies::providers(Strategies::teller())),
            new SimpleFinConnectSession(),
            Strategies::reviewQueue($transactions, $accounts),
            Strategies::reviewService($transactions, $accounts),
            Strategies::kingdomScopedCategorySearch(),
            Strategies::kingdomPatternService($kingdoms, $transactions),
            Strategies::patternWizard($kingdoms, $transactions, $accounts),
            Strategies::patternPrefill(),
            Strategies::ledgerSyncFeedback(),
            Strategies::patternAutomaticReview($kingdoms, $transactions, $accounts),
        );
    }

    public function testCategorySearchEmptyFlowReturnsEmptyResults(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/manage/golden-plains/taxonomy/categories')
            ->withQueryParams(['q' => 'rent']);
        $response = $this->manager->categorySearch($request, new Response(), 'golden-plains');

        self::assertSame(200, $response->getStatusCode());
        /** @var array{results: list<mixed>} $payload */
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([], $payload['results']);
    }

    public function testCategorySearchInvalidFlowReturnsEmptyResults(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/manage/golden-plains/taxonomy/categories')
            ->withQueryParams(['flow' => 'not-a-flow', 'q' => 'rent']);
        $response = $this->manager->categorySearch($request, new Response(), 'golden-plains');

        self::assertSame(200, $response->getStatusCode());
        /** @var array{results: list<mixed>} $payload */
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([], $payload['results']);
    }

    public function testCategorySearchValidRequestReturnsJson(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/manage/golden-plains/taxonomy/categories')
            ->withQueryParams(['flow' => 'expense', 'q' => 'site']);
        $response = $this->manager->categorySearch($request, new Response(), 'golden-plains');

        self::assertSame(200, $response->getStatusCode());
        /** @var array{results: list<array{label: string}>} $payload */
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNotEmpty($payload['results']);
    }
}
