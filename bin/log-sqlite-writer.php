#!/usr/bin/env php
<?php

declare(strict_types=1);

use Amtgard\Denarius\Utilities\Log\Sqlite\LogPathResolver;
use Amtgard\Denarius\Utilities\Log\Sqlite\LogSpoolDrainer;
use Amtgard\Denarius\Utilities\Log\Sqlite\MethodLogJsonLineParser;
use Amtgard\Denarius\Utilities\Log\Sqlite\SqliteLogSchema;

require __DIR__ . '/../vendor/autoload.php';

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

$paths = LogPathResolver::fromEnv($root);
$drainer = new LogSpoolDrainer($paths, new SqliteLogSchema(), new MethodLogJsonLineParser());

$once = in_array('--once', $argv, true);
$sleepMs = (int) ($_ENV['LOG_WRITER_SLEEP_MS'] ?? '200');

do {
    $count = $drainer->drainOnce();
    if ($once) {
        fwrite(STDOUT, "drained {$count} line(s)\n");
        break;
    }
    usleep(max(50, $sleepMs) * 1000);
} while (true);
