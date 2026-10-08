<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit\Config;

use Amtgard\Denarius\Domain\Bank\Provider\Providers\Plaid\Impl\CurlPlaidApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Plaid\Impl\IntegPlaidApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Plaid\PlaidApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\Impl\CurlSimpleFinApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\Impl\IntegSimpleFinApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\SimpleFinApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Stripe\Impl\CurlStripeApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Stripe\Impl\IntegStripeApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Stripe\StripeApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Teller\Impl\CurlTellerApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Teller\Impl\IntegTellerApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Teller\TellerApi;
use Amtgard\Denarius\Utilities\Http\Impl\CurlOrkGetKingdomsGateway;
use Amtgard\Denarius\Utilities\Http\Integ\IntegIdpHttpGuard;
use Amtgard\Denarius\Utilities\Http\Integ\IntegOrkGetKingdomsGateway;
use Amtgard\Denarius\Utilities\Http\LoggingIdpHttpClient;
use Amtgard\Denarius\Utilities\Http\OrkGetKingdomsGateway;
use PHPUnit\Framework\TestCase;

/** Asserts outbound container includes without bootstrapping the full DI graph. */
final class IntegOutboundContainerTest extends TestCase
{
    public function testDevIntegOutboundDefinitionsUseStubs(): void
    {
        /** @var array<class-string, callable|object> $definitions */
        $definitions = require dirname(__DIR__, 3) . '/config/container/integ/outbound.php';

        $this->assertInstanceOf(IntegTellerApi::class, $this->resolve($definitions, TellerApi::class));
        $this->assertInstanceOf(IntegStripeApi::class, $this->resolve($definitions, StripeApi::class));
        $this->assertInstanceOf(IntegPlaidApi::class, $this->resolve($definitions, PlaidApi::class));
        $this->assertInstanceOf(IntegSimpleFinApi::class, $this->resolve($definitions, SimpleFinApi::class));
        $this->assertInstanceOf(IntegOrkGetKingdomsGateway::class, $this->resolve($definitions, OrkGetKingdomsGateway::class));

        $logging = $this->resolve($definitions, LoggingIdpHttpClient::class);
        $this->assertInstanceOf(LoggingIdpHttpClient::class, $logging);
        $inner = (new \ReflectionProperty($logging, 'inner'))->getValue($logging);
        $this->assertInstanceOf(IntegIdpHttpGuard::class, $inner);
    }

    public function testLiveOutboundDefinitionsUseCurlClients(): void
    {
        $_ENV['TELLER_CERT_PATH'] = '';
        $_ENV['TELLER_KEY_PATH'] = '';
        /** @var array<class-string, callable|object> $definitions */
        $definitions = require dirname(__DIR__, 3) . '/config/container/outbound.php';

        $this->assertInstanceOf(CurlTellerApi::class, $this->resolve($definitions, TellerApi::class));
        $this->assertInstanceOf(CurlStripeApi::class, $this->resolve($definitions, StripeApi::class));
        $this->assertInstanceOf(CurlPlaidApi::class, $this->resolve($definitions, PlaidApi::class));
        $this->assertInstanceOf(CurlSimpleFinApi::class, $this->resolve($definitions, SimpleFinApi::class));
        $this->assertInstanceOf(CurlOrkGetKingdomsGateway::class, $this->resolve($definitions, OrkGetKingdomsGateway::class));
    }

    /**
     * @param array<class-string, callable|object> $definitions
     * @param class-string $id
     */
    private function resolve(array $definitions, string $id): object
    {
        $factory = $definitions[$id] ?? null;
        $this->assertNotNull($factory, 'Missing definition for ' . $id);

        return is_callable($factory) ? $factory() : $factory;
    }
}
