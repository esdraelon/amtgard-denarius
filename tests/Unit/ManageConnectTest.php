<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Utilities\Auth\BootstrapAdmins;
use Amtgard\Denarius\Utilities\Auth\ClaimOrn;
use Amtgard\Denarius\Utilities\Auth\DenariusAuthorizer;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\Readiness\Impl\AlwaysReady;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\Registry\LedgerProviderRegistry;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\Support\PreviousMonthWindow;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\SimpleFinApi;
use Amtgard\Denarius\Controller\ManagerController;
use Amtgard\Denarius\Utilities\Http\TwigHtmlRenderer;
use Amtgard\Denarius\Persistence\Record\AccountRecord;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Utilities\Security\TokenCipher;
use Amtgard\Denarius\Service\Enrollment\BankConnect;
use Amtgard\Denarius\Service\Enrollment\EnrollmentService;
use Amtgard\Denarius\Service\Kingdom\KingdomSettings;
use Amtgard\Denarius\Service\Access\PermissionService;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\SimpleFinApplicationConfig;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\SimpleFinLedgerProvider;
use Amtgard\Denarius\Service\Enrollment\SimpleFinConnectSession;
use Amtgard\IdpClient\OAuth\TokenSet;
use Amtgard\IdpClient\Resource\AuthenticatedSession;
use Amtgard\IdpClient\Resource\OrkProfile;
use Amtgard\IdpClient\Resource\UserProfile;
use Amtgard\IdpClient\Session\SessionAuthStore;
use Amtgard\PHPUnit\AmtgardTestCase;
use DateTimeImmutable;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class ManageConnectTest extends AmtgardTestCase
{
    private ManagerController $manager;

    protected function setUp(): void
    {
        $_SESSION['_csrf'] = 'token';
        $_SESSION['test_session'] = (new AuthenticatedSession(
            new TokenSet('a'),
            new UserProfile('9', 'person@example.com', 'jwt', OrkProfile::fromArray(['kingdom_id' => 4, 'kingdom_name' => 'Golden Plains'])),
        ))->toSessionArray();

        $kingdoms = new MemoryKingdoms();
        $kingdoms->save(KingdomRecord::builder()
            ->orkKingdomId(4)
            ->name('Golden Plains')
            ->slug('golden-plains')
            ->visibility('public')
            ->displayMode('all')
            ->institutionName('First Credit Union')
            ->provider('teller')
            ->enrollmentId('enr_9')
            ->enrollmentStatus('disconnected')
            ->build());
        $accounts = new MemoryAccounts();
        $accounts->save(AccountRecord::builder()->kingdomId(1)->tellerAccountId('acc')->name('Checking')->type('depository')->published(true)->build());
        $permissions = new PermissionService(new FakePolicies([ClaimOrn::admin()]), new ArrayStore(), new DenariusAuthorizer(), BootstrapAdmins::fromEnv(null));
        $queue = new MemoryRefresh();
        $providers = new LedgerProviderRegistry([
            Strategies::teller(),
            new SimpleFinLedgerProvider(new class implements SimpleFinApi {
                public function claim(string $claimUrl): string
                {
                    return '';
                }

                public function accounts(string $accessUrl, int $startsAt, int $endsAt): array
                {
                    return [];
                }
            }, new AlwaysReady(), new PreviousMonthWindow(new DateTimeImmutable('2026-09-28')), new SimpleFinApplicationConfig('amtgard_denarius_dev', 'token', 'https://bridge.simplefin.org/simplefin')),
        ]);
        $transactions = new MemoryTransactions();
        $this->manager = new ManagerController(
            new SessionAuthStore('test_session'),
            $permissions,
            $kingdoms,
            $accounts,
            Strategies::kingdomSettings($kingdoms),
            new EnrollmentService($kingdoms, new MemorySecrets(), $accounts, $providers, new TokenCipher('k'), $queue, Strategies::months()),
            $queue,
            new TwigHtmlRenderer(new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'))),
            new BankConnect($providers),
            new SimpleFinConnectSession(),
            Strategies::reviewQueue($transactions, $accounts),
            Strategies::reviewService($transactions, $accounts),
        );
    }

    public function testAddBankMountsProvidersInOrderThenStops(): void
    {
        $shown = $this->body($this->manager->show($this->request('GET', '/manage/golden-plains'), new Response(), 'golden-plains'));
        $this->assertStringContainsString('Add bank', $shown);
        $this->assertStringNotContainsString('Find my bank', $shown);
        $this->assertStringNotContainsString('Refresh transactions', $shown);
        $this->assertStringContainsString('Bank access was disconnected', $shown);
        $this->assertStringNotContainsString('Connect with Teller', $shown);
        $this->assertStringContainsString('Checking', $shown);

        $teller = $this->body($this->manager->connect($this->request('POST', '/manage/golden-plains/connect', [
            'csrf' => 'token',
        ]), new Response(), 'golden-plains'));
        $this->assertStringContainsString('Connect with Teller', $teller);
        $this->assertStringContainsString('app_test', $teller);
        $this->assertStringContainsString('sandbox', $teller);
        $this->assertStringContainsString('enrollmentId: "enr_9"', $teller);
        $this->assertStringContainsString('Try another provider', $teller);

        $simple = $this->body($this->manager->connect($this->request('POST', '/manage/golden-plains/connect', [
            'csrf' => 'token',
            'current' => 'teller',
            'skip' => '1',
        ]), new Response(), 'golden-plains'));
        $this->assertStringContainsString('simplefin-connect', $simple);
        $this->assertStringContainsString('amtgard_denarius_dev', $simple);
        $this->assertStringContainsString('apps', $simple);

        $again = $this->body($this->manager->connect($this->request('POST', '/manage/golden-plains/connect', [
            'csrf' => 'token',
            'skipped' => ['teller', 'teller', ''],
            'current' => 'simplefin',
            'skip' => '1',
        ]), new Response(), 'golden-plains'));
        $this->assertStringContainsString('No configured provider is available', $again);

        $this->assertSame(403, $this->manager->connect($this->request('POST', '/connect', ['csrf' => 'nope']), new Response(), 'golden-plains')->getStatusCode());
        $this->assertSame(404, $this->manager->connect($this->request('POST', '/missing', ['csrf' => 'token']), new Response(), 'missing')->getStatusCode());
        $guestTransactions = new MemoryTransactions();
        $guestAccounts = new MemoryAccounts();
        $guest = new ManagerController(
            new SessionAuthStore('empty'),
            new PermissionService(new FakePolicies([]), new ArrayStore(), new DenariusAuthorizer(), BootstrapAdmins::fromEnv(null)),
            new MemoryKingdoms(),
            $guestAccounts,
            Strategies::kingdomSettings(new MemoryKingdoms()),
            new EnrollmentService(new MemoryKingdoms(), new MemorySecrets(), $guestAccounts, Strategies::providers(Strategies::teller()), new TokenCipher('k'), new MemoryRefresh(), Strategies::months()),
            new MemoryRefresh(),
            new TwigHtmlRenderer(new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'))),
            new BankConnect(Strategies::providers(Strategies::teller())),
            new SimpleFinConnectSession(),
            Strategies::reviewQueue($guestTransactions, $guestAccounts),
            Strategies::reviewService($guestTransactions, $guestAccounts),
        );
        $this->assertSame(302, $guest->connect($this->request('POST', '/manage/golden-plains/connect', ['csrf' => 'token']), new Response(), 'golden-plains')->getStatusCode());
    }

    public function testStripeAndPlaidWidgetsRenderFromConnectConfig(): void
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'));
        $kingdom = [
            'name' => 'Golden Plains',
            'slug' => 'golden-plains',
            'visibility' => 'public',
            'displayMode' => 'all',
            'enrollmentStatus' => 'none',
            'provider' => '',
            'enrollmentId' => null,
        ];
        $stripe = $twig->render('manage.twig', [
            'csrf' => 'token',
            'kingdom' => $kingdom,
            'accounts' => [],
            'reviewQueue' => [],
            'connect' => [
                'available' => true,
                'autostart' => true,
                'reason' => '',
                'provider' => 'stripe',
                'skipped' => [],
                'config' => ['publishableKey' => 'pk_test', 'clientSecret' => 'cs_test', 'customerId' => 'cus_1'],
            ],
        ]);
        $this->assertStringContainsString('Connect with Stripe', $stripe);
        $this->assertStringContainsString('pk_test', $stripe);
        $this->assertStringContainsString('cus_1', $stripe);

        $missingKey = $twig->render('connect/stripe.twig', [
            'connect' => ['config' => ['publishableKey' => '']],
        ]);
        $this->assertStringContainsString('Stripe is missing a publishable key.', $missingKey);

        $plaid = $twig->render('manage.twig', [
            'csrf' => 'token',
            'kingdom' => $kingdom,
            'accounts' => [],
            'reviewQueue' => [],
            'connect' => [
                'available' => true,
                'autostart' => true,
                'reason' => '',
                'provider' => 'plaid',
                'skipped' => [],
                'config' => ['linkToken' => 'link-sandbox'],
            ],
        ]);
        $this->assertStringContainsString('Connect with Plaid', $plaid);
        $this->assertStringContainsString('link-sandbox', $plaid);
    }

    private function request(string $method, string $path, array $body = []): \Psr\Http\Message\ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path);
        if ($body !== []) {
            $request = $request->withParsedBody($body);
        }

        return $request;
    }

    private function body(\Psr\Http\Message\ResponseInterface $response): string
    {
        return (string) $response->getBody();
    }
}
