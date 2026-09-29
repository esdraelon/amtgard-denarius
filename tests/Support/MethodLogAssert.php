<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Support;

use PHPUnit\Framework\Assert;

/** Test helper (Facade): assertions against the active {@see RecordingMethodLog}. */
final class MethodLogAssert
{
    public static function reset(): void
    {
        $recorder = self::requireRecorder();
        $recorder->reset();
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
