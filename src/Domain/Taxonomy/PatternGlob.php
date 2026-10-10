<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Strategy: glob-style token match (*, ?, \\s) inside normalized bank text (not full regex). */
final class PatternGlob
{
    public static function normalizeToken(string $raw): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($raw): string {
            $raw = trim($raw);
            if ($raw === '') {
                return '';
            }
            $upper = '';
            $length = strlen($raw);
            for ($index = 0; $index < $length; ++$index) {
                if ($raw[$index] === '\\' && ($index + 1) < $length && strtolower($raw[$index + 1]) === 's') {
                    $upper .= '\\s';
                    $index += 1;
                    continue;
                }
                $upper .= strtoupper($raw[$index]);
            }

            return $upper;
        });
    }

    public static function matchesInText(string $pattern, string $text): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($pattern, $text): bool {
            $pattern = self::normalizeToken($pattern);
            if ($pattern === '' || $text === '') {
                return false;
            }
            if (! self::usesGlob($pattern)) {
                return str_contains($text, $pattern);
            }

            return preg_match('/' . self::globToRegexFragment($pattern) . '/', $text) === 1;
        });
    }

    private static function usesGlob(string $pattern): bool
    {
        return DenariusLog::trace(__METHOD__, static fn (): bool => strpbrk($pattern, '*?') !== false
            || str_contains($pattern, '\\s'));
    }

    private static function globToRegexFragment(string $pattern): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($pattern): string {
            $parts = preg_split('/(\\\\s)/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE);
            if ($parts === false) {
                return preg_quote($pattern, '/');
            }
            $regex = '.*';
            foreach ($parts as $part) {
                if ($part === '\\s') {
                    $regex .= '\\s+';
                    continue;
                }
                $length = strlen($part);
                for ($index = 0; $index < $length; ++$index) {
                    $char = $part[$index];
                    $regex .= match ($char) {
                        '*' => '.*',
                        '?' => '.',
                        default => preg_quote($char, '/'),
                    };
                }
            }
            $regex .= '.*';

            return $regex;
        });
    }
}
