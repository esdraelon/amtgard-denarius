<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Log\Sqlite;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;

/** Adapter: buffer Monolog JSON lines and append to the spool file once per request (shutdown flush). */
final class JsonLogSpoolHandler extends AbstractProcessingHandler
{
    /** @var list<string> */
    private array $buffer = [];

    private bool $shutdownRegistered = false;

    public function __construct(
        private readonly LogPathResolver $paths,
    ) {
        parent::__construct();
    }

    protected function write(LogRecord $record): void
    {
        $this->buffer[] = $record->message . "\n";
        if ($this->shutdownRegistered) {
            return;
        }
        $this->shutdownRegistered = true;
        register_shutdown_function(function (): void {
            $this->flushBuffer();
        });
    }

    /** Visible for tests and explicit flush before process exit. */
    public function flushBuffer(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $spool = $this->paths->spoolFile();
        $this->paths->ensureDirectory($spool);
        $this->paths->ensureDirectory($this->paths->spoolDirectory());

        $chunk = implode('', $this->buffer);
        $this->buffer = [];
        @file_put_contents($spool, $chunk, FILE_APPEND | LOCK_EX);
    }
}
