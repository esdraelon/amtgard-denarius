<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Log;

/** Processor: copy an array and redact sensitive keys. */
final class RedactingContext
{
    private const KEYS = [
        'secret',
        'token',
        'password',
        'authorization',
        'cookie',
        'client_secret',
        'access_token',
        'refresh_token',
    ];

    /** @param array<mixed> $context */
    public static function redact(array $context, ?string $branch = null): array
    {
        if ($branch === IdpHttpTrafficLog::BRANCH && IdpHttpTrafficLog::plaintextExchanges()) {
            return $context;
        }

        return self::walk($context);
    }

    /** @param array<mixed> $context */
    private static function walk(array $context): array
    {
        $copy = [];
        foreach ($context as $key => $value) {
            if (is_string($key) && self::isSensitive($key)) {
                $copy[$key] = '[redacted]';
                continue;
            }
            $copy[$key] = is_array($value) ? self::walk($value) : $value;
        }

        return $copy;
    }

    private static function isSensitive(string $key): bool
    {
        return in_array(strtolower($key), self::KEYS, true);
    }
}
