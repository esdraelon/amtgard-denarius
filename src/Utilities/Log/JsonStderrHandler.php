<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Log;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;

/** Processor: write one log message line to a stream (stderr by default). */
final class JsonStderrHandler extends AbstractProcessingHandler
{
    /** @param resource $stream */
    public function __construct(private $stream = null)
    {
        parent::__construct();
        $this->stream ??= STDERR;
    }

    protected function write(LogRecord $record): void
    {
        fwrite($this->stream, $record->message . "\n");
    }
}
