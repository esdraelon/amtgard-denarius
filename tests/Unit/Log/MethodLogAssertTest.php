<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit\Log;

use Amtgard\Denarius\Tests\Support\MethodLogAssert;
use Amtgard\Denarius\Tests\Support\MethodLogRecorder;
use Amtgard\Denarius\Tests\Support\RecordingMethodLog;
use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Amtgard\PHPUnit\AmtgardTestCase;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;

final class MethodLogAssertTest extends AmtgardTestCase
{
    private RecordingMethodLog $recorder;

    protected function setUp(): void
    {
        $active = MethodLogRecorder::active();
        $this->assertInstanceOf(RecordingMethodLog::class, $active);
        $this->recorder = $active;
        MethodLogAssert::resetTraces();
    }

    public function testResetClearsRecorder(): void
    {
        DenariusLog::trace('Amtgard\\Denarius\\Tests\\Support\\Example::run', static fn (): int => 1);
        MethodLogAssert::resetTraces();
        $this->assertSame([], $this->recorder->entered());
    }

    public function testAssertTracedPassesWhenMethodWasTraced(): void
    {
        $method = 'Amtgard\\Denarius\\Tests\\Support\\Example::run';
        DenariusLog::trace($method, static fn (): int => 1);
        MethodLogAssert::assertTraced($method);
        $this->addToAssertionCount(1);
    }

    public function testAssertTracedPassesWhenMethodFailed(): void
    {
        $method = 'Amtgard\\Denarius\\Tests\\Support\\Example::fail';
        try {
            DenariusLog::trace($method, static function (): void {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
        }
        MethodLogAssert::assertTraced($method);
        $this->addToAssertionCount(1);
    }

    public function testAssertTracedFailsWhenMethodNotCalled(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Expected method Amtgard\\Denarius\\Tests\\Support\\Missing::run to be traced');
        MethodLogAssert::assertTraced('Amtgard\\Denarius\\Tests\\Support\\Missing::run');
    }

    public function testAssertConstructorEnteredPassesWhenEntered(): void
    {
        $method = 'Amtgard\\Denarius\\Tests\\Support\\Example::__construct';
        DenariusLog::enter($method);
        MethodLogAssert::assertConstructorEntered($method);
        $this->addToAssertionCount(1);
    }

    public function testAssertConstructorEnteredFailsWhenNotEntered(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Expected constructor Amtgard\\Denarius\\Tests\\Support\\Missing::__construct to be entered');
        MethodLogAssert::assertConstructorEntered('Amtgard\\Denarius\\Tests\\Support\\Missing::__construct');
    }

    public function testAssertAnyOfTracedPassesWhenOneMatches(): void
    {
        DenariusLog::trace('Amtgard\\Denarius\\Tests\\Support\\Example::a', static fn (): int => 1);
        MethodLogAssert::assertAnyOfTraced(
            'Amtgard\\Denarius\\Tests\\Support\\Missing::x',
            'Amtgard\\Denarius\\Tests\\Support\\Example::a',
        );
        $this->addToAssertionCount(1);
    }

    public function testAssertAnyOfTracedFailsWhenNoneMatch(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Expected at least one traced method among: Amtgard\\Denarius\\Tests\\Support\\Missing::a, Amtgard\\Denarius\\Tests\\Support\\Missing::b');
        MethodLogAssert::assertAnyOfTraced(
            'Amtgard\\Denarius\\Tests\\Support\\Missing::a',
            'Amtgard\\Denarius\\Tests\\Support\\Missing::b',
        );
    }
}
