<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Domain\Taxonomy\PatternGlob;
use Amtgard\PHPUnit\AmtgardTestCase;
use PHPUnit\Framework\Attributes\Test;

final class PatternGlobTest extends AmtgardTestCase
{
    #[Test]
    public function normalizeTokenPreservesWhitespaceEscape(): void
    {
        self::assertSame('USAA\\sFSB', PatternGlob::normalizeToken('usaa\\sfsb'));
        self::assertSame('COSTCO', PatternGlob::normalizeToken('costco'));
    }

    #[Test]
    public function plainTokenUsesSubstringMatch(): void
    {
        self::assertTrue(PatternGlob::matchesInText('USAA', 'USAA FSB TRNSFER'));
        self::assertFalse(PatternGlob::matchesInText('WALMART', 'USAA FSB TRNSFER'));
    }

    #[Test]
    public function globStarAndQuestionMarkMatch(): void
    {
        self::assertTrue(PatternGlob::matchesInText('USAA*FSB', 'USAA FSB TRNSFER'));
        self::assertTrue(PatternGlob::matchesInText('USAA?FSB', 'USAAxFSB TRNSFER'));
    }

    #[Test]
    public function globWhitespaceEscapeMatchesFlexibleSpaces(): void
    {
        self::assertTrue(PatternGlob::matchesInText('USAA\\sFSB', 'USAA FSB TRNSFER'));
        self::assertTrue(PatternGlob::matchesInText('USAA\\sFSB', 'USAA   FSB TRNSFER'));
    }
}
