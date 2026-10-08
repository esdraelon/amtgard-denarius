<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if ((getenv('ENVIRONMENT') ?: '') !== 'DEV_INTEG') {
    fwrite(STDERR, "seed.php requires ENVIRONMENT=DEV_INTEG\n");
    exit(1);
}

// Fixture seeding expands in milestone C3 (per-test reseed).
