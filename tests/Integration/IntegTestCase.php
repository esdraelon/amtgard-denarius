<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Integration;

use Amtgard\Denarius\Tests\Integration\Support\IntegFixtureReseeder;
use PHPUnit\Framework\TestCase;

abstract class IntegTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        IntegFixtureReseeder::reseedForTest();
    }

    protected function integBaseUrl(): string
    {
        $base = rtrim((string) (getenv('DENARIUS_BASE_URL') ?: $_ENV['DENARIUS_BASE_URL'] ?? ''), '/');
        if ($base === '') {
            self::fail('DENARIUS_BASE_URL is not set.');
        }

        return $base;
    }
}
