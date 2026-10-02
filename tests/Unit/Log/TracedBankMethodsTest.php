<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit\Log;

use Amtgard\Denarius\Tests\Support\BankDomainArrange;
use Amtgard\Denarius\Tests\Support\MethodLogAssert;
use Amtgard\Denarius\Tests\Support\TracedMethodCatalog;
use Amtgard\Denarius\Tests\Unit\ApplicationTest;
use Amtgard\Denarius\Utilities\Auth\CurrentActor;
use Amtgard\PHPUnit\AmtgardTestCase;

final class TracedBankMethodsTest extends AmtgardTestCase
{
    protected function tearDown(): void
    {
        CurrentActor::reset();
        unset($_SESSION['test_session'], $_SESSION['_csrf']);
        parent::tearDown();
    }

    public function testEveryBankTraceSiteIsAsserted(): void
    {
        MethodLogAssert::reset();
        class_exists(ApplicationTest::class);

        BankDomainArrange::exerciseAll();

        $scope = $this->methodsInScope();
        $this->assertCount(184, $scope);
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
    private function methodsInScope(): array
    {
        $catalog = TracedMethodCatalog::forProject();
        $scoped = [];
        foreach ($catalog->all() as $method) {
            if (str_contains($method, '\\Domain\\Bank\\')) {
                $scoped[] = $method;
            }
        }

        return $scoped;
    }
}
