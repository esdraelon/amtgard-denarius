<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Http;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class BuildInfo
{
    public static function version(string $root): string
    {
        return DenariusLog::trace(__METHOD__, static function () use ($root): string {
            $path = rtrim($root, '/') . '/VERSION';
            if (!is_file($path)) {
                return 'dev';
            }

            $version = trim((string) file_get_contents($path));

            return $version === '' ? 'dev' : $version;
        });
    }
}
