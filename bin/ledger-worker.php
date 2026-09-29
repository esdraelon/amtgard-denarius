<?php

declare(strict_types=1);

use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Amtgard\Denarius\Utilities\Log\MethodLog;
use Amtgard\Denarius\Utilities\Log\RequestLogContext;
use Amtgard\Denarius\Worker\LedgerWorker;

$container = require __DIR__ . '/../config/bootstrap.php';
DenariusLog::install($container->get(MethodLog::class));
RequestLogContext::set(bin2hex(random_bytes(8)));
$worker = $container->get(LedgerWorker::class);
$worker->run();
