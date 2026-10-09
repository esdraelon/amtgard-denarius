<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit\Integ;

use PHPUnit\Framework\TestCase;

/** Asserts ledger-worker integ overlay matches web integ DB/Redis hosts. */
final class IntegWorkerComposeTest extends TestCase
{
    public function testWorkerIntegOverlayMatchesWebIntegHosts(): void
    {
        $yaml = (string) file_get_contents(dirname(__DIR__, 3) . '/docker/compose.worker.integ.yml');
        $expected = [
            'ENVIRONMENT: DEV_INTEG',
            'DB_HOST: amtgard-denarius-db-integ',
            'DB_NAME: denarius_integ',
            'SESSION_REDIS_HOST: amtgard-denarius-sessions-integ',
            'REDIS_HOST: amtgard-denarius-sessions-integ',
            'REDIS_PORT: "6379"',
            'REDIS_DB: "0"',
            'SESSION_REDIS_DB: "1"',
        ];
        foreach ($expected as $line) {
            $this->assertStringContainsString($line, $yaml, 'compose.worker.integ.yml must set ' . $line);
        }
    }

    public function testIntegScriptsWireWorkerIntegOverlay(): void
    {
        $root = dirname(__DIR__, 3);
        $up = (string) file_get_contents($root . '/scripts/integ-up.sh');
        $down = (string) file_get_contents($root . '/scripts/integ-down.sh');

        $this->assertStringContainsString('compose.worker.integ.yml', $up);
        $this->assertStringContainsString('compose_worker up -d', $up);
        $this->assertStringContainsString('compose_worker_dev up -d', $down);
        $this->assertStringNotContainsString('compose.worker.integ.yml', $down);
    }
}
