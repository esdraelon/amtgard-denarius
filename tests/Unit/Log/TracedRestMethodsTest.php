<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit\Log;

use Amtgard\Denarius\Tests\Support\MethodLogAssert;
use Amtgard\Denarius\Tests\Support\RestDomainArrange;
use Amtgard\Denarius\Tests\Support\TracedMethodCatalog;
use Amtgard\Denarius\Tests\Unit\ApplicationTest;
use Amtgard\Denarius\Utilities\Auth\CurrentActor;
use Amtgard\PHPUnit\AmtgardTestCase;

final class TracedRestMethodsTest extends AmtgardTestCase
{
    protected function tearDown(): void
    {
        CurrentActor::reset();
        unset($_SESSION['test_session'], $_SESSION['_csrf']);
        parent::tearDown();
    }

    public function testEveryRestTraceSiteIsAsserted(): void
    {
        MethodLogAssert::resetTraces();
        class_exists(ApplicationTest::class);

        RestDomainArrange::exerciseAll();

        $scope = $this->methodsInRestScope();
        $this->assertCount(293, $scope);
        foreach ($scope as $method) {
            if (str_ends_with($method, '::__construct')) {
                MethodLogAssert::assertConstructorEntered($method);
            } else {
                MethodLogAssert::assertTraced($method);
            }
            $this->addToAssertionCount(1);
        }
    }

    /** Closes the catalog: Utilities/Log correlation sites (494/494 with M-02–M-07). */
    public function testEveryUtilitiesLogTraceSiteIsAsserted(): void
    {
        MethodLogAssert::resetTraces();

        RestDomainArrange::exerciseUtilitiesLog();

        $scope = $this->methodsInLogScope();
        $this->assertCount(5, $scope);
        foreach ($scope as $method) {
            if (str_ends_with($method, '::__construct')) {
                MethodLogAssert::assertConstructorEntered($method);
            } else {
                MethodLogAssert::assertTraced($method);
            }
            $this->addToAssertionCount(1);
        }
    }

    /**
     * @return list<string>
     */
    private function methodsInRestScope(): array
    {
        $catalog = TracedMethodCatalog::forProject();
        $scoped = [];
        foreach ($catalog->all() as $method) {
            if ($this->isRestScope($method)) {
                $scoped[] = $method;
            }
        }

        return $scoped;
    }

    /**
     * @return list<string>
     */
    private function methodsInLogScope(): array
    {
        $catalog = TracedMethodCatalog::forProject();
        $scoped = [];
        foreach ($catalog->all() as $method) {
            if (str_contains($method, '\\Utilities\\Log\\')) {
                $scoped[] = $method;
            }
        }

        return $scoped;
    }

    private function isRestScope(string $method): bool
    {
        return str_contains($method, '\\Domain\\Statement\\')
            || str_contains($method, '\\Domain\\Taxonomy\\')
            || str_contains($method, '\\Domain\\Kingdom\\')
            || str_contains($method, '\\Utilities\\Setup\\')
            || str_contains($method, '\\Utilities\\Queue\\')
            || str_contains($method, '\\Utilities\\Session\\')
            || str_contains($method, '\\Utilities\\Security\\');
    }
}
