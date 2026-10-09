#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

INTEG_JUNIT="$ROOT/build/integ-junit.xml"
integ_summary=""

KEEP=false
for arg in "$@"; do
    if [[ "$arg" == "--keep" ]]; then
        KEEP=true
    fi
done

if ! docker info >/dev/null 2>&1; then
    echo "Docker is not running; integration harness requires the dev stack." >&2
    exit 1
fi

fail=0
if ! ./scripts/integ-up.sh; then
    fail=1
fi

if [[ "$fail" -eq 0 ]]; then
    echo "==> Running integration tests (testdox)..."
    mkdir -p "$ROOT/build"
    if ! composer integ -- --log-junit "$INTEG_JUNIT"; then
        fail=1
    fi
    if [[ "$fail" -eq 0 ]]; then
        echo "==> Checking integ route matrix coverage..."
        if ! php bin/check-integ-route-coverage.php docs/planning/dev-integ-route-matrix.md 90; then
            fail=1
        fi
    fi
    if [[ -s "$INTEG_JUNIT" ]]; then
        integ_summary="$(php -r '
            $path = $argv[1];
            $xml = @simplexml_load_file($path);
            if ($xml === false) {
                fwrite(STDERR, "Could not read JUnit summary from {$path}\n");
                exit(1);
            }
            $root = $xml->testsuites->testsuite ?? $xml->testsuite ?? null;
            if ($root === null) {
                fwrite(STDERR, "Could not read JUnit summary from {$path}\n");
                exit(1);
            }
            $attrs = $root->attributes();
            $tests = (int) ($attrs["tests"] ?? 0);
            $failures = (int) ($attrs["failures"] ?? 0);
            $errors = (int) ($attrs["errors"] ?? 0);
            $skipped = (int) ($attrs["skipped"] ?? 0);
            $failed = $failures + $errors;
            $passed = max(0, $tests - $failed - $skipped);
            echo "{$passed} passed, {$failed} failed";
        ' "$INTEG_JUNIT")" || fail=1
    fi
fi

if [[ "$KEEP" == false ]]; then
    if ! ./scripts/integ-down.sh; then
        fail=1
    fi
fi

if [[ -n "$integ_summary" ]]; then
    echo "$integ_summary"
fi

exit "$fail"
