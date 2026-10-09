<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Domain\Taxonomy\RegexPatternGuard;
use Amtgard\Denarius\Domain\Taxonomy\TaxonomyCatalogValidationException;
use Amtgard\PHPUnit\AmtgardTestCase;

final class RegexPatternGuardTest extends AmtgardTestCase
{
    public function testAllowsBoundedKeywordPattern(): void
    {
        $guard = new RegexPatternGuard();
        $guard->assertSafe('\\bFEE\\b', 'kw.test');
        $this->addToAssertionCount(1);
    }

    public function testRejectsNestedQuantifiers(): void
    {
        $guard = new RegexPatternGuard();
        $this->expectException(TaxonomyCatalogValidationException::class);
        $guard->assertSafe('(a+)+', 'kw.evil');
    }

    public function testRejectsCompileFailure(): void
    {
        $guard = new RegexPatternGuard();
        $this->expectException(TaxonomyCatalogValidationException::class);
        $guard->assertSafe('(?(', 'kw.bad');
    }
}
