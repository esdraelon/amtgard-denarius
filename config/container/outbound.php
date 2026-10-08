<?php

declare(strict_types=1);

use Amtgard\Denarius\Domain\Bank\Provider\Providers\Plaid\Impl\CurlPlaidApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Plaid\PlaidApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\Impl\CurlSimpleFinApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\SimpleFinApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\SimpleFinHost;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Stripe\Impl\CurlStripeApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Stripe\StripeApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Teller\Impl\CurlTellerApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\Teller\TellerApi;
use Amtgard\Denarius\Utilities\Auth\Impl\IdpPolicyGateway;
use Amtgard\Denarius\Utilities\Auth\PolicyGateway;
use Amtgard\Denarius\Utilities\Http\IdpUserDirectory;
use Amtgard\Denarius\Utilities\Http\Impl\CurlOrkGetKingdomsGateway;
use Amtgard\Denarius\Utilities\Http\LoggingIdpHttpClient;
use Amtgard\Denarius\Utilities\Http\OrkGetKingdomsGateway;
use Amtgard\IdpClient\Client\IdpClient;
use Amtgard\IdpClient\Config\IdpClientEnvironmentFactory;
use Amtgard\IdpClient\Config\IdpClientFactory;
use Psr\Container\ContainerInterface;

/** Live outbound HTTP bindings (production and unit tests). */
return [
    LoggingIdpHttpClient::class => function () {
        $environment = IdpClientEnvironmentFactory::fromEnvVars();
        $inner = new \GuzzleHttp\Client([
            'headers' => [
                'User-Agent' => $environment->httpUserAgent(),
                'Accept' => 'application/json',
            ],
        ]);

        return new LoggingIdpHttpClient($inner);
    },
    IdpClient::class => fn (ContainerInterface $c) => IdpClientFactory::fromEnvVars(null, null, $c->get(LoggingIdpHttpClient::class)),
    PolicyGateway::class => fn (IdpClient $idp) => new IdpPolicyGateway($idp->clientIam()),
    OrkGetKingdomsGateway::class => fn () => new CurlOrkGetKingdomsGateway(
        $_ENV['ORK_API_BASE_URL'] ?? 'https://ork.amtgard.com',
        $_ENV['ORK_API_USER_AGENT'] ?? 'Amtgard-Denarius',
        $_ENV['ORK_API_REFERER'] ?? 'https://denarius.amtgard.com',
    ),
    TellerApi::class => fn () => new CurlTellerApi(
        $_ENV['TELLER_API_BASE'] ?? 'https://api.teller.io',
        $_ENV['TELLER_CERT_PATH'] ?? '',
        $_ENV['TELLER_KEY_PATH'] ?? '',
    ),
    StripeApi::class => fn () => new CurlStripeApi(
        $_ENV['STRIPE_API_BASE'] ?? 'https://api.stripe.com',
        $_ENV['STRIPE_SECRET_KEY'] ?? '',
    ),
    PlaidApi::class => fn () => new CurlPlaidApi(
        $_ENV['PLAID_API_BASE'] ?? 'https://sandbox.plaid.com',
        $_ENV['PLAID_CLIENT_ID'] ?? '',
        $_ENV['PLAID_SECRET'] ?? '',
        $_ENV['PLAID_CLIENT_NAME'] ?? 'Denarius',
    ),
    SimpleFinApi::class => fn () => new CurlSimpleFinApi(new SimpleFinHost(['simplefin.org'])),
    IdpUserDirectory::class => function (ContainerInterface $c) {
        $psr17 = new \Nyholm\Psr7\Factory\Psr17Factory();

        return new IdpUserDirectory(
            IdpClientEnvironmentFactory::fromEnvVars(),
            $c->get(LoggingIdpHttpClient::class),
            $psr17,
        );
    },
];
