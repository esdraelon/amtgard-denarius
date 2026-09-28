<?php

declare(strict_types=1);

use Amtgard\Denarius\Setup\ConsoleIo;
use Amtgard\Denarius\Setup\CurlSetupClient;
use Amtgard\Denarius\Setup\EnvFragment;
use Amtgard\Denarius\Setup\HiddenLine;
use Amtgard\Denarius\Setup\PlaidGuide;
use Amtgard\Denarius\Setup\ProviderSetup;
use Amtgard\Denarius\Setup\RequiredSettings;
use Amtgard\Denarius\Setup\SimpleFinGuide;
use Amtgard\Denarius\Setup\StripeGuide;
use Amtgard\Denarius\Setup\TellerGuide;

require dirname(__DIR__) . '/vendor/autoload.php';

$io = new ConsoleIo(STDIN, STDOUT, static fn (string $command): void => exec($command));
$client = new CurlSetupClient();
$required = new RequiredSettings();
$setup = new ProviderSetup(
    [
        new StripeGuide($client, $required, $_ENV['STRIPE_API_BASE'] ?? 'https://api.stripe.com'),
        new PlaidGuide($client, $required, $_ENV['PLAID_API_BASE'] ?? 'https://sandbox.plaid.com'),
        new TellerGuide($client, $required),
        new SimpleFinGuide(),
    ],
    new HiddenLine($io),
    new EnvFragment(),
    $io,
);

exit($setup->run($argv[1] ?? 'provider.env'));
