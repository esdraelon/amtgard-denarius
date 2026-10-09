<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();

return [
    'paths' => [
        'migrations' => 'db/migrations',
        'seeds' => 'db/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'development',
        'development' => [
            'adapter' => 'mysql',
            'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
            'name' => $_ENV['DB_NAME'] ?? 'denarius',
            'user' => $_ENV['DB_USER'] ?? 'denarius',
            'pass' => $_ENV['DB_PASS'] ?? 'secret',
            'port' => $_ENV['DB_PORT'] ?? '3306',
            'charset' => 'utf8mb4',
        ],
        'production' => [
            'adapter' => 'mysql',
            'host' => $_ENV['DB_HOST'] ?? 'host.docker.internal',
            'name' => $_ENV['DB_NAME'] ?? 'denarius',
            'user' => $_ENV['DB_USER'] ?? 'denarius',
            'pass' => $_ENV['DB_PASS'] ?? '',
            'port' => $_ENV['DB_PORT'] ?? '3306',
            'charset' => 'utf8mb4',
        ],
        'testing' => [
            'adapter' => 'mysql',
            'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
            'name' => $_ENV['DB_NAME'] ?? 'denarius_test',
            'user' => $_ENV['DB_USER'] ?? 'denarius',
            'pass' => $_ENV['DB_PASS'] ?? 'secret',
            'port' => $_ENV['DB_PORT'] ?? '36316',
            'charset' => 'utf8mb4',
        ],
    ],
];
