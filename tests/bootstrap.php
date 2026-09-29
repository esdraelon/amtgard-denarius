<?php

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');

require dirname(__DIR__) . '/vendor/autoload.php';

DG\BypassFinals::enable();

use Amtgard\Denarius\Tests\Support\RecordingMethodLog;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

DenariusLog::install(new RecordingMethodLog());
