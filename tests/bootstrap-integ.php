<?php

declare(strict_types=1);

/**
 * Integ PHPUnit bootstrap: suppress vendor deprecations before Composer autoload.
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');

require __DIR__ . '/bootstrap.php';
