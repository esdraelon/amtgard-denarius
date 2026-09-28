<?php

declare(strict_types=1);

use Amtgard\Denarius\Utilities\Setup\Io\Impl\ConsoleIo;
use Amtgard\Denarius\Utilities\Setup\Client\Impl\CurlSetupClient;
use Amtgard\Denarius\Utilities\Setup\Env\EnvFragment;
use Amtgard\Denarius\Utilities\Setup\Io\HiddenLine;
use Amtgard\Denarius\Utilities\Setup\Guide\Impl\PlaidGuide;
use Amtgard\Denarius\Utilities\Setup\ProviderSetup;
use Amtgard\Denarius\Utilities\Setup\Field\RequiredSettings;
use Amtgard\Denarius\Utilities\Setup\Guide\Impl\SimpleFinGuide;
use Amtgard\Denarius\Utilities\Setup\Guide\Impl\StripeGuide;
use Amtgard\Denarius\Utilities\Setup\Guide\Impl\TellerGuide;

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
