#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Re-run the transaction categorizer for non-manager rows with stale taxonomy_version or uncategorized slug.
 *
 *   php bin/recategorize-transactions.php
 *   php bin/recategorize-transactions.php --ork-kingdom-id=12
 */

use Amtgard\Denarius\Service\Ledger\TransactionRecategorizer;
use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Amtgard\Denarius\Utilities\Log\MethodLog;
use Amtgard\Denarius\Utilities\Log\RequestLogContext;
use Amtgard\Denarius\Utilities\Log\StderrMethodLog;

require dirname(__DIR__) . '/vendor/autoload.php';

DenariusLog::install(StderrMethodLog::create(false));
RequestLogContext::set(bin2hex(random_bytes(8)));

$orkKingdomId = 0;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--ork-kingdom-id=(\d+)$/', $arg, $matches) === 1) {
        $orkKingdomId = (int) $matches[1];
    }
}

$container = require dirname(__DIR__) . '/config/bootstrap.php';
DenariusLog::install($container->get(MethodLog::class));

/** @var TransactionRecategorizer $recategorizer */
$recategorizer = $container->get(TransactionRecategorizer::class);
if ($orkKingdomId > 0) {
    $updated = $recategorizer->recategorizeKingdomByOrkId($orkKingdomId);
    fwrite(STDOUT, "Recategorized {$updated} transaction(s) for ORK kingdom {$orkKingdomId}.\n");
    exit(0);
}

$recategorizer->recategorizeAll();
fwrite(STDOUT, "Recategorize pass completed for all kingdoms.\n");
