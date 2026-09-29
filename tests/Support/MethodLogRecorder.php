<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Support;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Access to the RecordingMethodLog installed on DenariusLog. */
final class MethodLogRecorder
{
    public static function active(): ?RecordingMethodLog
    {
        $property = (new \ReflectionClass(DenariusLog::class))->getProperty('logger');
        $installed = $property->getValue();

        return $installed instanceof RecordingMethodLog ? $installed : null;
    }
}
