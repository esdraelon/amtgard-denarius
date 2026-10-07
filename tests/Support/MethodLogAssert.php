<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Support;

use Amtgard\Denarius\Utilities\Log\BranchLogLevel;
use PHPUnit\Framework\Assert;

/** Test helper (Facade): assertions against the active {@see RecordingMethodLog}. */
final class MethodLogAssert
{
    public static function reset(): void
    {
        $recorder = self::requireRecorder();
        $recorder->reset();
    }

    public static function resetTraces(): void
    {
        $recorder = self::requireRecorder();
        $recorder->resetTraces();
    }

    public static function assertTraced(string $method): void
    {
        $recorder = self::requireRecorder();
        if (! self::wasTraced($recorder, $method)) {
            Assert::fail(sprintf(
                'Expected method %s to be traced (entered and left or failed) but it was not.',
                $method,
            ));
        }
    }

    public static function assertConstructorEntered(string $method): void
    {
        $recorder = self::requireRecorder();
        if (! in_array($method, $recorder->entered(), true)) {
            Assert::fail(sprintf(
                'Expected constructor %s to be entered but it was not.',
                $method,
            ));
        }
    }

    public static function assertBranchLogged(BranchLogLevel $level, string $branch, string $method): void
    {
        $recorder = self::requireRecorder();
        foreach ($recorder->branches() as $entry) {
            if ($entry['level'] === $level && $entry['branch'] === $branch && $entry['method'] === $method) {
                return;
            }
        }

        Assert::fail(sprintf(
            'Expected branch %s at %s (%s) but it was not logged.',
            $branch,
            $method,
            $level->value,
        ));
    }

    public static function assertBranchContextExcludes(string $branch, string $method, string ...$forbiddenKeys): void
    {
        $recorder = self::requireRecorder();
        foreach ($recorder->branches() as $entry) {
            if ($entry['branch'] !== $branch || $entry['method'] !== $method) {
                continue;
            }
            foreach ($forbiddenKeys as $key) {
                if (array_key_exists($key, $entry['context'])) {
                    Assert::fail(sprintf(
                        'Branch %s at %s must not log context key %s.',
                        $branch,
                        $method,
                        $key,
                    ));
                }
            }
            foreach ($entry['context'] as $value) {
                if (is_string($value) && self::looksLikeDescriptionOrCounterparty($value)) {
                    Assert::fail(sprintf(
                        'Branch %s at %s must not log description or counterparty text (%s).',
                        $branch,
                        $method,
                        $value,
                    ));
                }
            }

            return;
        }

        Assert::fail(sprintf('Expected branch %s at %s but it was not logged.', $branch, $method));
    }

    private static function looksLikeDescriptionOrCounterparty(string $value): bool
    {
        $upper = strtoupper($value);
        if (str_contains($upper, 'RECREATION.GOV') || str_contains($upper, 'K&K INSURANCE')) {
            return true;
        }

        return str_contains($upper, 'CAFE') && str_contains($upper, 'LUNCH');
    }

    public static function assertAnyOfTraced(string ...$methods): void
    {
        $recorder = self::requireRecorder();
        foreach ($methods as $method) {
            if (self::wasTraced($recorder, $method)) {
                return;
            }
        }

        Assert::fail(sprintf(
            'Expected at least one traced method among: %s',
            implode(', ', $methods),
        ));
    }

    private static function requireRecorder(): RecordingMethodLog
    {
        $recorder = MethodLogRecorder::active();
        if ($recorder === null) {
            Assert::fail('RecordingMethodLog is not installed on DenariusLog.');
        }

        return $recorder;
    }

    private static function wasTraced(RecordingMethodLog $recorder, string $method): bool
    {
        if (! in_array($method, $recorder->entered(), true)) {
            return false;
        }

        return in_array($method, $recorder->left(), true)
            || in_array($method, $recorder->failed(), true);
    }
}
