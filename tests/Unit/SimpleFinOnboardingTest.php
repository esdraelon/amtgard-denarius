<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Controller\SimpleFinReturnController;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\Readiness\Impl\AlwaysReady;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\Registry\LedgerProviderRegistry;
use Amtgard\Denarius\Domain\Bank\Provider\Framework\Support\PreviousMonthWindow;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\SimpleFinApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\SimpleFinApplicationConfig;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\SimpleFinSetupToken;
use Amtgard\Denarius\Utilities\Http\AppPublicUrl;
use Amtgard\Denarius\Utilities\Http\CsrfToken;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\SimpleFinLedgerProvider;
use Amtgard\Denarius\Service\Enrollment\EnrollmentService;
use Amtgard\Denarius\Service\Enrollment\SimpleFinConnectSession;
use Amtgard\Denarius\Service\Enrollment\SimpleFinReturnEnrollment;
use Amtgard\Denarius\Service\Enrollment\SimpleFinReturnException;
use Amtgard\Denarius\Service\Access\PermissionService;
use Amtgard\Denarius\Utilities\Auth\BootstrapAdmins;
use Amtgard\Denarius\Utilities\Auth\ClaimOrn;
use Amtgard\Denarius\Utilities\Auth\DenariusAuthorizer;
use Amtgard\Denarius\Utilities\Http\TwigHtmlRenderer;
use Amtgard\Denarius\Utilities\Security\TokenCipher;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
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

final class SimpleFinOnboardingTest extends AmtgardTestCase
{
    public function testApplicationConfigBuildsAppCreateUrl(): void
    {
        $_ENV['APP_PUBLIC_URL'] = 'http://localhost:37180';
        $config = new SimpleFinApplicationConfig('amtgard_denarius_dev', 'secret', 'https://bridge.simplefin.org/simplefin');
        $this->assertTrue($config->configured());
        $this->assertStringContainsString('/apps/amtgard_denarius_dev/create', $config->userCreateUrl());
        $this->assertStringContainsString('return_url=', $config->userCreateUrl());
        $this->assertSame('http://localhost:37180/bank/simplefin/return', $config->returnUrl());
    }

    public function testReturnRouteClaimsConnectionTokenForRememberedKingdom(): void
    {
        class_exists(ApplicationTest::class);
        $_SESSION['_csrf'] = 'token';
        $_SESSION['test_session'] = (new AuthenticatedSession(
            new TokenSet('a'),
            new UserProfile('9', 'person@example.com', 'jwt', OrkProfile::fromArray(['kingdom_id' => 4, 'kingdom_name' => 'Golden Plains'])),
        ))->toSessionArray();

        $kingdoms = new MemoryKingdoms();
        $kingdoms->save(KingdomRecord::builder()->orkKingdomId(4)->name('Golden Plains')->slug('golden-plains')->build());
        $accounts = new MemoryAccounts();
        $providers = new LedgerProviderRegistry([
            new SimpleFinLedgerProvider(new class implements SimpleFinApi {
                public function claim(string $claimUrl): string
                {
                    return 'https://user:secret@bridge.simplefin.org/simplefin';
                }

                public function accounts(string $accessUrl, int $startsAt, int $endsAt): array
                {
                    return [];
                }
            }, new AlwaysReady(), new PreviousMonthWindow(new DateTimeImmutable('2026-09-28')), new SimpleFinApplicationConfig('app', 'tok', 'https://bridge.simplefin.org/simplefin')),
        ]);
        $queue = new MemoryRefresh();
        $permissions = new PermissionService(new FakePolicies([ClaimOrn::admin()]), new ArrayStore(), new DenariusAuthorizer(), BootstrapAdmins::fromEnv(null));
        $session = new SimpleFinConnectSession();
        $session->remember('golden-plains');
        $enrollment = new SimpleFinReturnEnrollment($kingdoms, new EnrollmentService($kingdoms, new MemorySecrets(), $accounts, $providers, new TokenCipher('k'), $queue, Strategies::months(), Strategies::bankReset()), $session, $permissions);
        $controller = new SimpleFinReturnController(
            new SessionAuthStore('test_session'),
            $enrollment,
            new TwigHtmlRenderer(new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'))),
        );

        $token = base64_encode('https://bridge.simplefin.org/simplefin/claim/abc');
        SimpleFinSetupToken::decode($token);
        $response = $controller->show(
            (new ServerRequestFactory())->createServerRequest('GET', '/bank/simplefin/return?setup_token=' . rawurlencode($token)),
            new Response(),
        );
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/manage/golden-plains', $response->getHeaderLine('Location'));
    }

    public function testFromEnvAndReturnErrors(): void
    {
        class_exists(ApplicationTest::class);
        $_ENV['SIMPLEFIN_APP_ID'] = 'amtgard_denarius_dev';
        $_ENV['SIMPLEFIN_APP_TOKEN'] = 'secret';
        $this->assertTrue(SimpleFinApplicationConfig::fromEnv()->configured());

        $kingdoms = new MemoryKingdoms();
        $kingdoms->save(KingdomRecord::builder()->orkKingdomId(4)->name('Golden Plains')->slug('golden-plains')->build());
        $permissions = new PermissionService(new FakePolicies([ClaimOrn::admin()]), new ArrayStore(), new DenariusAuthorizer(), BootstrapAdmins::fromEnv(null));
        $enrollment = new SimpleFinReturnEnrollment(
            $kingdoms,
            new EnrollmentService($kingdoms, new MemorySecrets(), new MemoryAccounts(), Strategies::providers(Strategies::teller()), new TokenCipher('k'), new MemoryRefresh(), Strategies::months(), Strategies::bankReset()),
            new SimpleFinConnectSession(),
            $permissions,
        );

        $session = new SimpleFinConnectSession();
        $session->remember('golden-plains');
        try {
            $enrollment->complete('9', []);
            $this->fail('Expected missing token.');
        } catch (SimpleFinReturnException $exception) {
            $this->assertSame('missing_token', $exception->reason());
        }

        unset($_ENV['APP_PUBLIC_URL']);
        $_ENV['IDP_REDIRECT_URI'] = 'http://localhost:37180/oauth/callback';
        $this->assertSame('http://localhost:37180', AppPublicUrl::base());

        $this->assertThrows(\InvalidArgumentException::class, fn () => SimpleFinSetupToken::decode('not-valid'));

        $badProviders = new LedgerProviderRegistry([
            new SimpleFinLedgerProvider(new class implements SimpleFinApi {
                public function claim(string $claimUrl): string
                {
                    return 'Forbidden (was it already claimed?)';
                }

                public function accounts(string $accessUrl, int $startsAt, int $endsAt): array
                {
                    return [];
                }
            }, new AlwaysReady(), new PreviousMonthWindow(new DateTimeImmutable('2026-09-28')), new SimpleFinApplicationConfig('app', 'tok', 'https://bridge.simplefin.org/simplefin')),
        ]);
        $claimFail = new SimpleFinReturnEnrollment(
            $kingdoms,
            new EnrollmentService($kingdoms, new MemorySecrets(), new MemoryAccounts(), $badProviders, new TokenCipher('k'), new MemoryRefresh(), Strategies::months(), Strategies::bankReset()),
            new SimpleFinConnectSession(),
            $permissions,
        );
        $token = base64_encode('https://bridge.simplefin.org/simplefin/claim/bad');
        try {
            $claimFail->complete('9', ['setup_token' => $token, 'kingdom' => 'golden-plains']);
            $this->fail('Expected claim failure.');
        } catch (SimpleFinReturnException $exception) {
            $this->assertSame('claim_failed', $exception->reason());
        }
    }

    public function testReturnControllerAndEnrollmentGuards(): void
    {
        class_exists(ApplicationTest::class);
        $_SESSION['_csrf'] = 'token';
        $_SESSION['test_session'] = (new AuthenticatedSession(
            new TokenSet('a'),
            new UserProfile('9', 'person@example.com', 'jwt', OrkProfile::fromArray(['kingdom_id' => 4, 'kingdom_name' => 'Golden Plains'])),
        ))->toSessionArray();

        $kingdoms = new MemoryKingdoms();
        $kingdoms->save(KingdomRecord::builder()->orkKingdomId(4)->name('Golden Plains')->slug('golden-plains')->build());
        $memberOnly = new PermissionService(new FakePolicies([]), new ArrayStore(), new DenariusAuthorizer(), BootstrapAdmins::fromEnv(null));
        $enrollment = new SimpleFinReturnEnrollment(
            $kingdoms,
            new EnrollmentService($kingdoms, new MemorySecrets(), new MemoryAccounts(), Strategies::providers(Strategies::teller()), new TokenCipher('k'), new MemoryRefresh(), Strategies::months(), Strategies::bankReset()),
            new SimpleFinConnectSession(),
            $memberOnly,
        );
        $token = base64_encode('https://bridge.simplefin.org/simplefin/claim/abc');
        try {
            $enrollment->complete('9', ['setup_token' => $token, 'kingdom' => 'golden-plains']);
            $this->fail('Expected forbidden.');
        } catch (SimpleFinReturnException $exception) {
            $this->assertSame('forbidden', $exception->reason());
        }
        try {
            $enrollment->complete('9', ['setup_token' => $token, 'kingdom' => 'missing']);
            $this->fail('Expected missing kingdom.');
        } catch (SimpleFinReturnException $exception) {
            $this->assertSame('missing_kingdom', $exception->reason());
        }

        unset($_ENV['APP_PUBLIC_URL'], $_ENV['IDP_REDIRECT_URI']);
        $this->assertSame('', AppPublicUrl::base());
        $this->assertSame('', AppPublicUrl::path('/bank/simplefin/return'));
        $_ENV['IDP_REDIRECT_URI'] = 'not-valid';
        $this->assertSame('', AppPublicUrl::base());
        $noReturn = new SimpleFinApplicationConfig('app', 'tok', 'https://bridge.simplefin.org/simplefin');
        $this->assertSame('https://bridge.simplefin.org/simplefin/apps/app/create', $noReturn->userCreateUrl());

        $badProviders = new LedgerProviderRegistry([
            new SimpleFinLedgerProvider(new class implements SimpleFinApi {
                public function claim(string $claimUrl): string
                {
                    return 'Forbidden (was it already claimed?)';
                }

                public function accounts(string $accessUrl, int $startsAt, int $endsAt): array
                {
                    return [];
                }
            }, new AlwaysReady(), new PreviousMonthWindow(new DateTimeImmutable('2026-09-28')), new SimpleFinApplicationConfig('app', 'tok', 'https://bridge.simplefin.org/simplefin')),
        ]);
        $failEnrollment = new SimpleFinReturnEnrollment(
            $kingdoms,
            new EnrollmentService($kingdoms, new MemorySecrets(), new MemoryAccounts(), $badProviders, new TokenCipher('k'), new MemoryRefresh(), Strategies::months(), Strategies::bankReset()),
            new SimpleFinConnectSession(),
            new PermissionService(new FakePolicies([ClaimOrn::admin()]), new ArrayStore(), new DenariusAuthorizer(), BootstrapAdmins::fromEnv(null)),
        );
        $twig = new TwigHtmlRenderer(new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates')));
        $controller = new SimpleFinReturnController(new SessionAuthStore('test_session'), $failEnrollment, $twig);

        $form = $controller->show((new ServerRequestFactory())->createServerRequest('GET', '/bank/simplefin/return'), new Response());
        $this->assertSame(200, $form->getStatusCode());
        $this->assertStringContainsString('setup_token', (string) $form->getBody());

        $failed = $controller->show(
            (new ServerRequestFactory())->createServerRequest('GET', '/bank/simplefin/return?setup_token=' . rawurlencode($token)),
            new Response(),
        );
        $this->assertSame(400, $failed->getStatusCode());

        $_SESSION['_csrf'] = CsrfToken::issue();
        $posted = $controller->submit(
            (new ServerRequestFactory())->createServerRequest('POST', '/bank/simplefin/return')->withParsedBody([
                'csrf' => $_SESSION['_csrf'],
                'setup_token' => $token,
            ]),
            new Response(),
        );
        $this->assertSame(400, $posted->getStatusCode());

        $guest = new SimpleFinReturnController(
            new SessionAuthStore('empty'),
            $failEnrollment,
            $twig,
        );
        $guestResponse = $guest->submit(
            (new ServerRequestFactory())->createServerRequest('POST', '/bank/simplefin/return')->withParsedBody(['csrf' => 'token']),
            new Response(),
        );
        $this->assertSame(302, $guestResponse->getStatusCode());
        $this->assertSame('/login', $guestResponse->getHeaderLine('Location'));
    }
}
