<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit\Log;

use Amtgard\Denarius\Utilities\Log\Sqlite\JsonLogSpoolHandler;
use Amtgard\Denarius\Utilities\Log\Sqlite\LogPathResolver;
use Amtgard\Denarius\Utilities\Log\Sqlite\LogSpoolDrainer;
use Amtgard\Denarius\Utilities\Log\Sqlite\MethodLogJsonLineParser;
use Amtgard\Denarius\Utilities\Log\Sqlite\SqliteLogQuery;
use Amtgard\Denarius\Utilities\Log\Sqlite\SqliteLogSchema;
use Amtgard\PHPUnit\AmtgardTestCase;
use Monolog\Level;
use Monolog\LogRecord;

final class SqliteLogBundleTest extends AmtgardTestCase
{
    private string $tmpdir;

    protected function setUp(): void
    {
        $this->tmpdir = sys_get_temp_dir() . '/denarius-log-' . bin2hex(random_bytes(4));
        mkdir($this->tmpdir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpdir);
    }

    public function testSpoolDrainsIntoHourlySqliteAndQueryFindsRequestId(): void
    {
        $paths = new LogPathResolver($this->tmpdir);
        $handler = new JsonLogSpoolHandler($paths);
        $line = json_encode([
            'time' => '2026-10-08T12:00:01+00:00',
            'level' => 'info',
            'channel' => 'http',
            'event' => 'enter',
            'method' => 'Amtgard\\Denarius\\Controller\\HomeController::home',
            'request_id' => 'req-abc-123',
            'context' => [],
        ], JSON_THROW_ON_ERROR);
        $handler->handle(new LogRecord(
            new \DateTimeImmutable('2026-10-08T12:00:01+00:00'),
            'denarius',
            Level::Info,
            $line,
            [],
        ));
        $handler->flushBuffer();

        $drainer = new LogSpoolDrainer($paths, new SqliteLogSchema(), new MethodLogJsonLineParser());
        $this->assertSame(1, $drainer->drainOnce());
        $this->assertSame(0, $drainer->drainOnce());

        $found = (new SqliteLogQuery($paths, new SqliteLogSchema()))->rawLinesForRequestId('req-abc-123');
        $this->assertCount(1, $found);
        $this->assertStringContainsString('req-abc-123', $found[0]);
    }

    public function testParserIgnoresBlankAndInvalidLines(): void
    {
        $parser = new MethodLogJsonLineParser();
        $this->assertNull($parser->parse(''));
        $this->assertNull($parser->parse('not-json'));
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
