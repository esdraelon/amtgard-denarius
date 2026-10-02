<?php

declare(strict_types=1);

$path = $argv[1] ?? '';
$minimum = (float) ($argv[2] ?? '95');

if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "check-coverage: clover file missing.\n");
    exit(1);
}

$xml = simplexml_load_file($path);
if ($xml === false) {
    fwrite(STDERR, "check-coverage: clover file is invalid.\n");
    exit(1);
}

$metrics = $xml->project->metrics ?? null;
$statements = (int) ($metrics['statements'] ?? 0);
$covered = (int) ($metrics['coveredstatements'] ?? 0);
$percent = $statements === 0 ? 0.0 : ($covered / $statements) * 100;

printf("Line coverage: %.2f%% (%d/%d)\n", $percent, $covered, $statements);

if ($percent + 0.0001 < $minimum) {
    fwrite(STDERR, sprintf("check-coverage: %.2f%% is below the %.2f%% gate.\n", $percent, $minimum));
    exit(1);
}
