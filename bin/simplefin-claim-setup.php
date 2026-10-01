#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Claim a one-time SimpleFIN setup token (connection token, not the app registration secret).
 * Usage: php bin/simplefin-claim-setup.php [base64-setup-token]
 *        Reads SIMPLEFIN_APP_TOKEN from .env when no argument is given (dev verification only).
 */

require __DIR__ . '/../vendor/autoload.php';

Dotenv\Dotenv::createMutable(dirname(__DIR__))->safeLoad();

use Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\Impl\CurlSimpleFinApi;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\SimpleFinHost;
use Amtgard\Denarius\Domain\Bank\Provider\Providers\SimpleFin\SimpleFinSetupToken;

$token = trim($argv[1] ?? (string) ($_ENV['SIMPLEFIN_APP_TOKEN'] ?? ''));
if ($token === '') {
    fwrite(STDERR, "Provide a setup token argument or set SIMPLEFIN_APP_TOKEN in .env.\n");
    exit(1);
}

try {
    $claimUrl = SimpleFinSetupToken::decode($token)->claimUrl();
} catch (\InvalidArgumentException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}

$api = new CurlSimpleFinApi(new SimpleFinHost(['simplefin.org']));
$accessUrl = trim($api->claim($claimUrl));
if ($accessUrl === '' || SimpleFinSetupToken::forbidden($accessUrl)) {
    fwrite(STDERR, "Claim failed: " . ($accessUrl !== '' ? $accessUrl : '(empty response)') . "\n");
    exit(1);
}

fwrite(STDOUT, "Claim succeeded. Store the access URL securely (not in git):\n{$accessUrl}\n");
