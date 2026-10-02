<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Log;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;

/** Processor: write one log message line to a stream (stderr by default). */
final class JsonStderrHandler extends AbstractProcessingHandler
{
    /** @param resource|null $stream */
    public function __construct(private $stream = null)
    {
        parent::__construct();
        if ($this->stream === null) {
            $this->stream = self::openStderr();
        }
    }

    /** @return resource */
    private static function openStderr()
    {
        if (defined('STDERR')) {
            $stderr = \STDERR;
            if (is_resource($stderr)) {
                return $stderr;
            }
        }

        $opened = fopen('php://stderr', 'wb');
        if ($opened === false) {
            throw new \RuntimeException('Unable to open php://stderr for logging.');
        }

        return $opened;
    }

    protected function write(LogRecord $record): void
    {
        fwrite($this->stream, $record->message . "\n");
    }
}
