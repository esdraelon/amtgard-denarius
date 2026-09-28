<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Teller;

final class TellerWebhookVerifier
{
    public function __construct(
        private readonly string $secret,
        private readonly int $toleranceSeconds = 300,
    ) {
    }

    public function verify(string $body, ?string $header, int $now): bool
    {
        if ($header === null || $header === '' || $this->secret === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            $piece = trim($part);
            if (str_starts_with($piece, 't=')) {
                $timestamp = substr($piece, 2);
            } elseif (str_starts_with($piece, 'v1=')) {
                $signatures[] = substr($piece, 3);
            }
        }

        if ($timestamp === null || !ctype_digit($timestamp) || $signatures === []) {
            return false;
        }

        $age = abs($now - (int) $timestamp);
        if ($age > $this->toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $body, $this->secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
