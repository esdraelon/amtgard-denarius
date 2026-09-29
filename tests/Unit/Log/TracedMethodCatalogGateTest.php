<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit\Log;

use Amtgard\Denarius\Tests\Support\TracedMethodCatalog;
use Amtgard\Denarius\Tests\Support\TracedMethodCoverageManifest;
use Amtgard\PHPUnit\AmtgardTestCase;
use ReflectionClass;

final class TracedMethodCatalogGateTest extends AmtgardTestCase
{
    public function testEveryCatalogMethodMapsToMethodLogAssertMilestone(): void
    {
        $catalog = TracedMethodCatalog::forProject();
        $unmapped = TracedMethodCoverageManifest::unmappedCatalogMethods($catalog);
        $this->assertSame(
            [],
            $unmapped,
            'Add DenariusLog trace coverage (and a manifest scope) for: ' . implode(', ', $unmapped),
        );

        $mapped = TracedMethodCoverageManifest::mapCatalog($catalog);
        $this->assertSame($catalog->all(), array_keys($mapped));
    }

    public function testManifestScopesReferenceExistingAssertTests(): void
    {
        foreach (TracedMethodCoverageManifest::scopes() as $scope) {
            $this->assertTrue(
                class_exists($scope['test']),
                sprintf('Scope %s references missing test class %s', $scope['label'], $scope['test']),
            );
            $reflection = new ReflectionClass($scope['test']);
            $this->assertTrue(
                $reflection->isSubclassOf(AmtgardTestCase::class),
                sprintf('Scope %s test must extend AmtgardTestCase', $scope['label']),
            );
        }
    }
}
