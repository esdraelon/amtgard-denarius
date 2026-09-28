<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Auth\BootstrapAdmins;
use Amtgard\Denarius\Auth\ClaimOrn;
use Amtgard\Denarius\Auth\DenariusAuthorizer;
use Amtgard\Denarius\Bank\AlwaysReady;
use Amtgard\Denarius\Bank\LedgerProviderRegistry;
use Amtgard\Denarius\Bank\PreviousMonthWindow;
use Amtgard\Denarius\Bank\SimpleFin\SimpleFinApi;
use Amtgard\Denarius\Controller\ManagerController;
use Amtgard\Denarius\Http\TwigHtmlRenderer;
use Amtgard\Denarius\Record\AccountRecord;
use Amtgard\Denarius\Record\KingdomRecord;
use Amtgard\Denarius\Security\TokenCipher;
use Amtgard\Denarius\Service\BankConnect;
use Amtgard\Denarius\Service\EnrollmentService;
use Amtgard\Denarius\Service\KingdomSettings;
use Amtgard\Denarius\Service\PermissionService;
use Amtgard\Denarius\Bank\SimpleFin\SimpleFinLedgerProvider;
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
            new UserProfile(9, 'person@example.com', 'jwt', OrkProfile::fromArray(['kingdom_id' => 4, 'kingdom_name' => 'Golden Plains'])),
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
            }, new AlwaysReady(), new PreviousMonthWindow(new DateTimeImmutable('2026-09-28'))),
        ]);
        $this->manager = new ManagerController(
            new SessionAuthStore('test_session'),
            $permissions,
            $kingdoms,
            $accounts,
            new KingdomSettings($kingdoms),
            new EnrollmentService($kingdoms, new MemorySecrets(), $accounts, $providers, new TokenCipher('k'), $queue, Strategies::months()),
            $queue,
            new TwigHtmlRenderer(new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'))),
            new BankConnect($providers),
        );
    }

    public function testANamedBankMountsTellerThenSimpleFinThenStops(): void
    {
        $shown = $this->body($this->manager->show($this->request('GET', '/manage/golden-plains'), new Response(), ['slug' => 'golden-plains']));
        $this->assertStringContainsString('value="First Credit Union"', $shown);
        $this->assertStringContainsString('Reconnect this kingdom from the bank form.', $shown);
        $this->assertStringNotContainsString('Connect with Teller', $shown);
        $this->assertStringContainsString('Checking', $shown);

        $teller = $this->body($this->manager->connect($this->request('POST', '/manage/golden-plains/connect', [
            'csrf' => 'token',
            'institution' => '  First Bank  ',
            'skipped' => 'teller',
            'current' => 'simplefin',
        ]), new Response(), ['slug' => 'golden-plains']));
        $this->assertStringContainsString('Connect with Teller', $teller);
        $this->assertStringContainsString('app_test', $teller);
        $this->assertStringContainsString('sandbox', $teller);
        $this->assertStringContainsString('enrollmentId: "enr_9"', $teller);
        $this->assertStringContainsString('value="First Bank"', $teller);
        $this->assertStringNotContainsString('value="simplefin"', $teller);

        $simple = $this->body($this->manager->connect($this->request('POST', '/manage/golden-plains/connect', [
            'csrf' => 'token',
            'institution' => 'First Bank',
            'current' => 'teller',
            'skip' => '1',
        ]), new Response(), ['slug' => 'golden-plains']));
        $this->assertStringContainsString('simplefin-token', $simple);
        $this->assertSame(2, substr_count($simple, 'value="teller"'));

        $again = $this->body($this->manager->connect($this->request('POST', '/manage/golden-plains/connect', [
            'csrf' => 'token',
            'institution' => 'First Bank',
            'skipped' => ['teller', 'teller', ''],
            'current' => 'simplefin',
            'skip' => '1',
        ]), new Response(), ['slug' => 'golden-plains']));
        $this->assertStringContainsString('No configured provider can connect this bank.', $again);
        $this->assertSame(1, substr_count($again, 'value="teller"'));
        $this->assertSame(1, substr_count($again, 'value="simplefin"'));

        $blank = $this->body($this->manager->connect($this->request('POST', '/manage/golden-plains/connect', [
            'csrf' => 'token',
            'institution' => '   ',
            'skip' => '1',
            'current' => '',
        ]), new Response(), ['slug' => 'golden-plains']));
        $this->assertStringContainsString('Enter the bank name.', $blank);

        $this->assertSame(403, $this->manager->connect($this->request('POST', '/connect', ['csrf' => 'nope', 'institution' => 'First Bank']), new Response(), ['slug' => 'golden-plains'])->getStatusCode());
        $this->assertSame(404, $this->manager->connect($this->request('POST', '/missing', ['csrf' => 'token', 'institution' => 'First Bank']), new Response(), ['slug' => 'missing'])->getStatusCode());
        $guest = new ManagerController(
            new SessionAuthStore('empty'),
            new PermissionService(new FakePolicies([]), new ArrayStore(), new DenariusAuthorizer(), BootstrapAdmins::fromEnv(null)),
            new MemoryKingdoms(),
            new MemoryAccounts(),
            new KingdomSettings(new MemoryKingdoms()),
            new EnrollmentService(new MemoryKingdoms(), new MemorySecrets(), new MemoryAccounts(), Strategies::providers(Strategies::teller()), new TokenCipher('k'), new MemoryRefresh(), Strategies::months()),
            new MemoryRefresh(),
            new TwigHtmlRenderer(new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'))),
            new BankConnect(Strategies::providers(Strategies::teller())),
        );
        $this->assertSame(302, $guest->connect($this->request('POST', '/manage/golden-plains/connect', ['csrf' => 'token']), new Response(), ['slug' => 'golden-plains'])->getStatusCode());
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
            'connect' => [
                'available' => true,
                'reason' => '',
                'provider' => 'stripe',
                'institution' => 'First Bank',
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
            'connect' => [
                'available' => true,
                'reason' => '',
                'provider' => 'plaid',
                'institution' => 'First Bank',
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
