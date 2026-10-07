<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Support;

use Amtgard\Denarius\Tests\Unit\Log\TracedAuthMethodsTest;
use Amtgard\Denarius\Tests\Unit\Log\TracedBankMethodsTest;
use Amtgard\Denarius\Tests\Unit\Log\TracedHttpMethodsTest;
use Amtgard\Denarius\Tests\Unit\Log\TracedPersistenceMethodsTest;
use Amtgard\Denarius\Tests\Unit\Log\TracedRestMethodsTest;
use Amtgard\Denarius\Tests\Unit\Log\TracedServicesMethodsTest;

/**
 * Registry: maps each {@see TracedMethodCatalog} entry to the milestone test that asserts it.
 *
 * @phpstan-type ScopeEntry array{label: string, test: class-string, matches: callable(string): bool}
 */
final class TracedMethodCoverageManifest
{
    /**
     * @return list<ScopeEntry>
     */
    public static function scopes(): array
    {
        return [
            [
                'label' => 'M-02 log-test-http',
                'test' => TracedHttpMethodsTest::class,
                'matches' => static fn (string $method): bool => str_contains($method, '\\Controller\\')
                    || str_contains($method, '\\Utilities\\Http\\'),
            ],
            [
                'label' => 'M-03 log-test-auth',
                'test' => TracedAuthMethodsTest::class,
                'matches' => static fn (string $method): bool => str_contains($method, '\\Utilities\\Auth\\')
                    || str_contains($method, '\\Domain\\Access\\')
                    || str_contains($method, '\\Service\\Access\\'),
            ],
            [
                'label' => 'M-04 log-test-persistence',
                'test' => TracedPersistenceMethodsTest::class,
                'matches' => static fn (string $method): bool => str_contains($method, '\\Persistence\\'),
            ],
            [
                'label' => 'M-05 log-test-services',
                'test' => TracedServicesMethodsTest::class,
                'matches' => static fn (string $method): bool => (str_contains($method, '\\Service\\')
                        && ! str_contains($method, '\\Service\\Access\\'))
                    || str_contains($method, '\\Worker\\'),
            ],
            [
                'label' => 'M-06 log-test-bank',
                'test' => TracedBankMethodsTest::class,
                'matches' => static fn (string $method): bool => str_contains($method, '\\Domain\\Bank\\'),
            ],
            [
                'label' => 'M-07 log-test-rest',
                'test' => TracedRestMethodsTest::class,
                'matches' => static fn (string $method): bool => str_contains($method, '\\Domain\\Statement\\')
                    || str_contains($method, '\\Domain\\Taxonomy\\')
                    || str_contains($method, '\\Domain\\Kingdom\\')
                    || str_contains($method, '\\Utilities\\Setup\\')
                    || str_contains($method, '\\Utilities\\Queue\\')
                    || str_contains($method, '\\Utilities\\Session\\')
                    || str_contains($method, '\\Utilities\\Security\\')
                    || str_contains($method, '\\Utilities\\Log\\'),
            ],
        ];
    }

    /**
     * @return array<string, string> method FQCN => scope label
     */
    public static function mapCatalog(TracedMethodCatalog $catalog): array
    {
        $mapped = [];
        foreach ($catalog->all() as $method) {
            $label = self::labelFor($method);
            if ($label !== null) {
                $mapped[$method] = $label;
            }
        }

        ksort($mapped, SORT_STRING);

        return $mapped;
    }

    /**
     * @return list<string>
     */
    public static function unmappedCatalogMethods(TracedMethodCatalog $catalog): array
    {
        $missing = [];
        foreach ($catalog->all() as $method) {
            if (self::labelFor($method) === null) {
                $missing[] = $method;
            }
        }

        return $missing;
    }

    private static function labelFor(string $method): ?string
    {
        foreach (self::scopes() as $scope) {
            if (($scope['matches'])($method)) {
                return $scope['label'];
            }
        }

        return null;
    }
}
