<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Http;

final class BuildInfo
{
    public static function version(string $root): string
    {
        $path = rtrim($root, '/') . '/VERSION';
        if (!is_file($path)) {
            return 'dev';
        }

        $version = trim((string) file_get_contents($path));

        return $version === '' ? 'dev' : $version;
    }
}
