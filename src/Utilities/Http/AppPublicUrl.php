<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Http;

use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Optional\Optional;

final class AppPublicUrl
{
    public static function base(): string
    {
        return DenariusLog::trace(__METHOD__, static function (): string {
            $explicit = trim((string) ($_ENV['APP_PUBLIC_URL'] ?? ''));
            if ($explicit !== '') {
                return rtrim($explicit, '/');
            }

            $redirect = trim((string) ($_ENV['IDP_REDIRECT_URI'] ?? ''));
            if ($redirect === '') {
                return '';
            }
            $parts = parse_url($redirect);
            if (!is_array($parts)) {
                return '';
            }
            $scheme = (string) ($parts['scheme'] ?? 'https');
            $host = (string) ($parts['host'] ?? '');
            if ($host === '') {
                return '';
            }
            $port = isset($parts['port']) ? ':' . $parts['port'] : '';

            return $scheme . '://' . $host . $port;
        });
    }

    public static function path(string $suffix): string
    {
        return DenariusLog::trace(__METHOD__, static function () use ($suffix): string {
            $base = self::base();
            $path = '/' . ltrim($suffix, '/');

            return Optional::ofNullable($base === '' ? null : $base . $path)->orElse('');
        });
    }
}
