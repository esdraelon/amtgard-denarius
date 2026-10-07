<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit\Log;

use Amtgard\Denarius\Tests\Support\TracedMethodCatalog;
use Amtgard\PHPUnit\AmtgardTestCase;

final class TracedMethodCatalogTest extends AmtgardTestCase
{
    private TracedMethodCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = TracedMethodCatalog::forProject();
    }

    public function testHomeControllerListsConstructorAndActions(): void
    {
        $path = dirname(__DIR__, 3) . '/src/Controller/HomeController.php';
        $methods = $this->catalog->methodsInFile($path);
        $this->assertSame(
            [
                'Amtgard\\Denarius\\Controller\\HomeController::__construct',
                'Amtgard\\Denarius\\Controller\\HomeController::home',
                'Amtgard\\Denarius\\Controller\\HomeController::privacyPolicy',
                'Amtgard\\Denarius\\Controller\\HomeController::version',
            ],
            $methods,
        );
    }

    public function testJsonBodyListsStaticWrite(): void
    {
        $path = dirname(__DIR__, 3) . '/src/Utilities/Http/JsonBody.php';
        $methods = $this->catalog->methodsInFile($path);
        $this->assertSame(
            ['Amtgard\\Denarius\\Utilities\\Http\\JsonBody::write'],
            $methods,
        );
    }

    public function testDisplayModeEnumListsFromStored(): void
    {
        $path = dirname(__DIR__, 3) . '/src/Domain/Statement/Presentation/DisplayMode.php';
        $methods = $this->catalog->methodsInFile($path);
        $this->assertSame(
            ['Amtgard\\Denarius\\Domain\\Statement\\Presentation\\DisplayMode::label'],
            $methods,
        );
    }

    public function testClaimOrnFileListsBothTypes(): void
    {
        $path = dirname(__DIR__, 3) . '/src/Utilities/Auth/ClaimOrn.php';
        $methods = $this->catalog->methodsInFile($path);
        $this->assertSame(
            [
                'Amtgard\\Denarius\\Utilities\\Auth\\ClaimOrn::admin',
                'Amtgard\\Denarius\\Utilities\\Auth\\ClaimOrn::manage',
                'Amtgard\\Denarius\\Utilities\\Auth\\ClaimOrn::parse',
                'Amtgard\\Denarius\\Utilities\\Auth\\ParsedClaim::__construct',
            ],
            $methods,
        );
    }

    public function testAllIncludesKnownMethodsAndIsSorted(): void
    {
        $all = $this->catalog->all();
        $this->assertContains('Amtgard\\Denarius\\Controller\\HomeController::home', $all);
        $this->assertContains('Amtgard\\Denarius\\Utilities\\Http\\JsonBody::write', $all);
        $sorted = $all;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $all);
        $this->assertGreaterThan(100, count($all));
    }
}
