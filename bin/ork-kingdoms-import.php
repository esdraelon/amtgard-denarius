#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Normalize an ORK Kingdom/GetKingdoms JSON snapshot for Denarius admin pickers.
 *
 * Cloudflare blocks Denarius from calling ork.amtgard.com directly in many environments.
 * While logged in on https://ork.amtgard.com, open DevTools → Console and run:
 *
 *   fetch('https://ork.amtgard.com/orkservice/Json/index.php?request=', {
 *     method: 'POST',
 *     headers: {'Content-Type': 'application/x-www-form-urlencoded'},
 *     body: 'call=Kingdom/GetKingdoms&request={}',
 *     credentials: 'include',
 *   }).then(r => r.json()).then(j => copy(JSON.stringify(j)))
 *
 * Paste the clipboard into a file, then:
 *
 *   php bin/ork-kingdoms-import.php /path/to/get-kingdoms.json
 *
 * Writes data/ork-kingdoms.json (override with ORK_KINGDOMS_CACHE in .env).
 */

use Amtgard\Denarius\Utilities\Http\OrkKingdomDirectory;
use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Amtgard\Denarius\Utilities\Log\StderrMethodLog;

require dirname(__DIR__) . '/vendor/autoload.php';

DenariusLog::install(StderrMethodLog::create(false));

$source = $argv[1] ?? 'php://stdin';
$raw = $source === 'php://stdin' ? stream_get_contents(STDIN) : file_get_contents($source);
if ($raw === false || trim($raw) === '') {
    fwrite(STDERR, "No JSON input.\n");
    exit(1);
}

$kingdoms = OrkKingdomDirectory::parse($raw);
if ($kingdoms === []) {
    fwrite(STDERR, "No kingdoms parsed (wrong format or ORK Status not success).\n");
    exit(1);
}

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
$relative = $_ENV['ORK_KINGDOMS_CACHE'] ?? OrkKingdomDirectory::DEFAULT_CACHE_FILE;
$target = str_starts_with($relative, '/') ? $relative : dirname(__DIR__) . '/' . $relative;
$dir = dirname($target);
if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
    fwrite(STDERR, "Cannot create directory: {$dir}\n");
    exit(1);
}

$payload = json_encode(['kingdoms' => $kingdoms], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
if (file_put_contents($target, $payload . "\n") === false) {
    fwrite(STDERR, "Write failed: {$target}\n");
    exit(1);
}

fwrite(STDOUT, 'Wrote ' . count($kingdoms) . " kingdoms to {$target}\n");
