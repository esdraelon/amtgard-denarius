<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit\Integ;

use Amtgard\Denarius\Domain\Bank\Provider\Providers\Plaid\Impl\IntegPlaidApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\Impl\IntegSimpleFinApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Stripe\Impl\IntegStripeApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Teller\Impl\IntegTellerApi;
use Amtgard\Denarius\Tests\Support\MethodLogAssert;
use Amtgard\Denarius\Utilities\Http\Integ\IntegIdpHttpGuard;
use Amtgard\Denarius\Utilities\Http\Integ\IntegOrkGetKingdomsGateway;
use Amtgard\Denarius\Utilities\Log\BranchLogLevel;
use Amtgard\PHPUnit\AmtgardTestCase;
use GuzzleHttp\Psr7\Request;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;

final class IntegOutboundStubsTest extends AmtgardTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        MethodLogAssert::reset();
    }

    public function testOrkStubLogsAndReturnsBundledJson(): void
    {
        $gateway = new IntegOrkGetKingdomsGateway(dirname(__DIR__, 3));
        $json = $gateway->getKingdomsJson();
        $this->assertIsString($json);
        $this->assertStringContainsString('"kingdoms"', $json);
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Info, 'integ_ork_kingdoms_stub_answered', IntegOrkGetKingdomsGateway::class . '::getKingdomsJson');
    }

    public function testTellerStubLogsAccounts(): void
    {
        $api = new IntegTellerApi();
        $accounts = $api->accounts('token');
        $this->assertNotEmpty($accounts);
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Info, 'integ_teller_stub_answered', IntegTellerApi::class . '::accounts');
    }

    public function testStripeStubLogsCustomerCreation(): void
    {
        $api = new IntegStripeApi();
        $this->assertSame('cus_integ', $api->createCustomer('slug')['id']);
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Info, 'integ_stripe_stub_answered', IntegStripeApi::class . '::createCustomer');
    }

    public function testPlaidStubLogsLinkToken(): void
    {
        $api = new IntegPlaidApi();
        $this->assertSame('link-integ-sandbox', $api->linkToken('slug')['link_token']);
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Info, 'integ_plaid_stub_answered', IntegPlaidApi::class . '::linkToken');
    }

    public function testSimpleFinStubLogsClaim(): void
    {
        $api = new IntegSimpleFinApi();
        $this->assertStringContainsString('bridge.simplefin.org', $api->claim('https://claim.example.test/x'));
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Info, 'integ_simplefin_stub_answered', IntegSimpleFinApi::class . '::claim');
    }

    public function testIdpGuardBlocksProductionHost(): void
    {
        $inner = new class implements ClientInterface {
            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                return new Response(200);
            }
        };
        $guard = new IntegIdpHttpGuard($inner);
        try {
            $guard->sendRequest(new Request('GET', 'https://idp.amtgard.com/.well-known/openid-configuration'));
            $this->fail('Expected production IDP host to be blocked.');
        } catch (\RuntimeException) {
            MethodLogAssert::assertBranchLogged(BranchLogLevel::Info, 'integ_idp_production_blocked', IntegIdpHttpGuard::class . '::sendRequest');
        }
    }

    public function testOrkStubLogsEmptyWhenBundledFileMissing(): void
    {
        $gateway = new IntegOrkGetKingdomsGateway(sys_get_temp_dir() . '/denarius-integ-missing-root');
        $this->assertNull($gateway->getKingdomsJson());
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Info, 'integ_ork_kingdoms_stub_empty', IntegOrkGetKingdomsGateway::class . '::getKingdomsJson');
    }

    public function testIdpGuardForwardsLocalhost(): void
    {
        $inner = new class implements ClientInterface {
            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                return new Response(204);
            }
        };
        $guard = new IntegIdpHttpGuard($inner);
        $response = $guard->sendRequest(new Request('GET', 'http://localhost:37080/version'));
        $this->assertSame(204, $response->getStatusCode());
        MethodLogAssert::assertBranchLogged(BranchLogLevel::Info, 'integ_idp_http_forwarded', IntegIdpHttpGuard::class . '::sendRequest');
    }
}
