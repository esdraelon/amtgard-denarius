<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Http;

final class CsrfToken
{
    public static function issue(): string
    {
        if (!isset($_SESSION['_csrf']) || !is_string($_SESSION['_csrf']) || $_SESSION['_csrf'] === '') {
            $_SESSION['_csrf'] = bin2hex(random_bytes(16));
        }

        return $_SESSION['_csrf'];
    }

    public static function matches(?string $token): bool
    {
        $expected = $_SESSION['_csrf'] ?? null;
        if (!is_string($expected) || !is_string($token) || $token === '') {
            return false;
        }

        return hash_equals($expected, $token);
    }
}
