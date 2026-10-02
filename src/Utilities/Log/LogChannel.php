<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Log;

/** Strategy: map a method namespace to a log channel name. */
final class LogChannel
{
    private const HTTP = 'http';
    private const AUTH = 'auth';
    private const LEDGER = 'ledger';
    private const APP = 'app';

    public static function fromMethod(string $method): string
    {
        $class = explode('::', $method, 2)[0];

        return match (true) {
            str_contains($class, '\\Controller\\'),
            str_contains($class, '\\Utilities\\Http\\') => self::HTTP,
            str_contains($class, '\\Utilities\\Auth\\'),
            str_contains($class, '\\Domain\\Access\\'),
            str_contains($class, '\\Service\\Access\\') => self::AUTH,
            str_contains($class, '\\Domain\\Bank\\'),
            str_contains($class, '\\Service\\Ledger\\'),
            str_contains($class, '\\Service\\Enrollment\\'),
            str_contains($class, '\\Worker\\') => self::LEDGER,
            default => self::APP,
        };
    }
}
