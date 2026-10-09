<?php

declare(strict_types=1);

use Amtgard\Denarius\Domain\Bank\Provider\Providers\Plaid\Impl\IntegPlaidApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Plaid\PlaidApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\Impl\IntegSimpleFinApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\SimpleFinApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Stripe\Impl\IntegStripeApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Stripe\StripeApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Teller\Impl\IntegTellerApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Teller\TellerApi;
use Amtgard\Denarius\Utilities\Auth\Impl\IdpPolicyGateway;
use Amtgard\Denarius\Utilities\Auth\PolicyGateway;
use Amtgard\Denarius\Utilities\Http\IdpUserDirectory;
use Amtgard\Denarius\Utilities\Http\Integ\IntegIdpHttpGuard;
use Amtgard\Denarius\Utilities\Http\Integ\IntegOrkGetKingdomsGateway;
use Amtgard\Denarius\Utilities\Http\LoggingIdpHttpClient;
use Amtgard\Denarius\Utilities\Http\OrkGetKingdomsGateway;
use Amtgard\IdpClient\Client\IdpClient;
use Amtgard\IdpClient\Config\IdpClientEnvironmentFactory;
use Amtgard\IdpClient\Config\IdpClientFactory;
use Psr\Container\ContainerInterface;

/** DEV_INTEG outbound bindings: stub ledger/ORK APIs; IDP may reach local integ stack only. */
return [
    LoggingIdpHttpClient::class => function () {
        $environment = IdpClientEnvironmentFactory::fromEnvVars();
        $inner = new IntegIdpHttpGuard(new \GuzzleHttp\Client([
            'headers' => [
                'User-Agent' => $environment->httpUserAgent(),
                'Accept' => 'application/json',
            ],
        ]));

        return new LoggingIdpHttpClient($inner);
    },
    IdpClient::class => fn (ContainerInterface $c) => IdpClientFactory::fromEnvVars(null, null, $c->get(LoggingIdpHttpClient::class)),
    PolicyGateway::class => fn (IdpClient $idp) => new IdpPolicyGateway($idp->clientIam()),
    OrkGetKingdomsGateway::class => fn () => new IntegOrkGetKingdomsGateway(dirname(__DIR__, 3)),
    TellerApi::class => fn () => new IntegTellerApi(),
    StripeApi::class => fn () => new IntegStripeApi(),
    PlaidApi::class => fn () => new IntegPlaidApi(),
    SimpleFinApi::class => fn () => new IntegSimpleFinApi(),
    IdpUserDirectory::class => function (ContainerInterface $c) {
        $psr17 = new \Nyholm\Psr7\Factory\Psr17Factory();

        return new IdpUserDirectory(
            IdpClientEnvironmentFactory::fromEnvVars(),
            $c->get(LoggingIdpHttpClient::class),
            $psr17,
        );
    },
];
