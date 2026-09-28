<?php

declare(strict_types=1);

use Amtgard\Denarius\Worker\LedgerWorker;

$container = require __DIR__ . '/../config/bootstrap.php';
$worker = $container->get(LedgerWorker::class);
$worker->run();
