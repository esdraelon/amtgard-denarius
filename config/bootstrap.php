<?php

declare(strict_types=1);

use DI\ContainerBuilder;

require __DIR__ . '/../vendor/autoload.php';

Dotenv\Dotenv::createMutable(__DIR__ . '/..')->safeLoad();

$debug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
error_reporting($debug ? (E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED) : (E_ERROR | E_PARSE));
ini_set('display_errors', $debug ? '1' : '0');

$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions(__DIR__ . '/container.php');
$containerBuilder->useAutowiring(true);

return $containerBuilder->build();
