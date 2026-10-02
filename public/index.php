<?php

declare(strict_types=1);

use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Amtgard\Denarius\Utilities\Log\MethodLog;
use DI\Bridge\Slim\Bridge;

$container = require __DIR__ . '/../config/bootstrap.php';
DenariusLog::install($container->get(MethodLog::class));

$app = Bridge::create($container);

(require __DIR__ . '/../config/middleware.php')($app);
(require __DIR__ . '/../config/routes.php')($app);

$app->run();
