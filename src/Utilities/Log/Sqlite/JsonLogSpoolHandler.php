<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Log\Sqlite;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;

/** Adapter: append Monolog JSON message lines to the spool file (non-blocking). */
final class JsonLogSpoolHandler extends AbstractProcessingHandler
{
    public function __construct(
        private readonly LogPathResolver $paths,
    ) {
        parent::__construct();
    }

    protected function write(LogRecord $record): void
    {
        $spool = $this->paths->spoolFile();
        $this->paths->ensureDirectory($spool);
        $this->paths->ensureDirectory($this->paths->spoolDirectory());

        $line = $record->message . "\n";
        $written = @file_put_contents($spool, $line, FILE_APPEND | LOCK_EX);
        if ($written === false) {
            return;
        }
    }
}
