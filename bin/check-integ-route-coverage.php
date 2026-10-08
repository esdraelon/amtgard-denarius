<?php

declare(strict_types=1);

$path = $argv[1] ?? '';
$minimum = (float) ($argv[2] ?? '90');

if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "check-integ-route-coverage: matrix file missing.\n");
    exit(1);
}

$markdown = file_get_contents($path);
if ($markdown === false) {
    fwrite(STDERR, "check-integ-route-coverage: could not read matrix file.\n");
    exit(1);
}

$summary = parseSummarySection($markdown);
if ($summary === null) {
    fwrite(STDERR, "check-integ-route-coverage: could not parse Summary section.\n");
    exit(1);
}

$matrix = parseMatrixCounts($markdown);
if ($matrix === null) {
    fwrite(STDERR, "check-integ-route-coverage: could not parse Matrix section.\n");
    exit(1);
}

if (!summaryMatchesMatrix($summary, $matrix)) {
    fwrite(
        STDERR,
        sprintf(
            "check-integ-route-coverage: Summary counts disagree with Matrix rows (summary y=%d n=%d excluded=%d total=%d; matrix y=%d n=%d excluded=%d total=%d).\n",
            $summary['y'],
            $summary['n'],
            $summary['excluded'],
            $summary['total'],
            $matrix['y'],
            $matrix['n'],
            $matrix['excluded'],
            $matrix['total'],
        ),
    );
    exit(1);
}

$inScope = $summary['in_scope'];
if ($inScope <= 0) {
    fwrite(STDERR, "check-integ-route-coverage: in-scope route count must be positive.\n");
    exit(1);
}

$percent = ($summary['y'] / $inScope) * 100;

printf(
    "Integ route coverage: %.2f%% (%d/%d in-scope, %d excluded of %d routes)\n",
    $percent,
    $summary['y'],
    $inScope,
    $summary['excluded'],
    $summary['total'],
);

if ($percent + 0.0001 < $minimum) {
    fwrite(
        STDERR,
        sprintf("check-integ-route-coverage: %.2f%% is below the %.2f%% gate.\n", $percent, $minimum),
    );
    exit(1);
}

/**
 * @return array{total: int, excluded: int, in_scope: int, y: int, n: int}|null
 */
function parseSummarySection(string $markdown): ?array
{
    if (!preg_match('/^## Summary\b.*$/m', $markdown, $heading, PREG_OFFSET_CAPTURE)) {
        return null;
    }

    $start = $heading[0][1];
    $rest = substr($markdown, $start);
    if (!preg_match('/^## Matrix\b/m', $rest, $matrixHeading, PREG_OFFSET_CAPTURE)) {
        return null;
    }

    $section = substr($rest, 0, $matrixHeading[0][1]);

    $total = summaryMetric($section, 'Routes in `config/routes.php`');
    $excluded = summaryMetric($section, '**excluded**');
    $inScope = summaryMetric($section, 'In scope (total − excluded)');
    $y = summaryMetric($section, '**y** (covered today)');
    $n = summaryMetric($section, '**n** (uncovered in scope)');

    if ($total === null || $excluded === null || $inScope === null || $y === null || $n === null) {
        return null;
    }

    return [
        'total' => $total,
        'excluded' => $excluded,
        'in_scope' => $inScope,
        'y' => $y,
        'n' => $n,
    ];
}

function summaryMetric(string $section, string $label): ?int
{
    $pattern = '/^\|\s*' . preg_quote($label, '/') . '\s*\|\s*(\d+)\s*\|/m';
    if (!preg_match($pattern, $section, $matches)) {
        return null;
    }

    return (int) $matches[1];
}

/**
 * @return array{total: int, excluded: int, y: int, n: int}|null
 */
function parseMatrixCounts(string $markdown): ?array
{
    if (!preg_match('/^## Matrix\b.*$/m', $markdown, $heading, PREG_OFFSET_CAPTURE)) {
        return null;
    }

    $table = substr($markdown, $heading[0][1]);
    if (!preg_match_all(
        '/^\|\s*(GET|POST)\s*\|\s*`[^`]+`\s*\|\s*(y|n|excluded)\s*\|/m',
        $table,
        $matches,
        PREG_SET_ORDER,
    )) {
        return null;
    }

    if ($matches === []) {
        return null;
    }

    $y = 0;
    $n = 0;
    $excluded = 0;
    foreach ($matches as $row) {
        match ($row[2]) {
            'y' => $y++,
            'n' => $n++,
            'excluded' => $excluded++,
            default => null,
        };
    }

    $total = count($matches);

    return [
        'total' => $total,
        'excluded' => $excluded,
        'y' => $y,
        'n' => $n,
    ];
}

/**
 * @param array{total: int, excluded: int, in_scope: int, y: int, n: int} $summary
 * @param array{total: int, excluded: int, y: int, n: int} $matrix
 */
function summaryMatchesMatrix(array $summary, array $matrix): bool
{
    if ($summary['total'] !== $matrix['total']) {
        return false;
    }
    if ($summary['excluded'] !== $matrix['excluded']) {
        return false;
    }
    if ($summary['y'] !== $matrix['y']) {
        return false;
    }
    if ($summary['n'] !== $matrix['n']) {
        return false;
    }
    if ($summary['in_scope'] !== $summary['total'] - $summary['excluded']) {
        return false;
    }
    if ($summary['y'] + $summary['n'] !== $summary['in_scope']) {
        return false;
    }

    return true;
}
