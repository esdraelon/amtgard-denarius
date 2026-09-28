<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Bank\Providers\Stripe;

final class StripeWebhookVerifier
{
    public function __construct(
        private readonly string $secret,
        private readonly int $toleranceSeconds = 300,
    ) {
    }

    public function verify(string $body, ?string $header, int $now): bool
    {
        $parsed = $this->header($header);
        if ($parsed === null || $this->secret === '') {
            return false;
        }
        if (!$this->fresh($parsed['timestamp'], $now)) {
            return false;
        }

        return $this->signed($parsed['timestamp'], $body, $parsed['signatures']);
    }

    /**
     * @return array{timestamp: string, signatures: list<string>}|null
     */
    private function header(?string $header): ?array
    {
        if ($header === null || $header === '') {
            return null;
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
            return null;
        }

        return ['timestamp' => $timestamp, 'signatures' => $signatures];
    }

    private function fresh(string $timestamp, int $now): bool
    {
        return abs($now - (int) $timestamp) <= $this->toleranceSeconds;
    }

    /**
     * @param list<string> $signatures
     */
    private function signed(string $timestamp, string $body, array $signatures): bool
    {
        $expected = hash_hmac('sha256', $timestamp . '.' . $body, $this->secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
