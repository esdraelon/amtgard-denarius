<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Setup\Io\Impl;

use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Amtgard\Denarius\Utilities\Setup\Io\TextIo;
use Optional\Optional;

final class ConsoleIo implements TextIo
{
    /**
     * @param resource $input
     * @param resource $output
     */
    public function __construct(
        private $input,
        private $output,
        private readonly \Closure $command,
        private readonly ?\Closure $tty = null,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function write(string $text): void
    {
        DenariusLog::trace(__METHOD__, function () use ($text): mixed {
            fwrite($this->output, $text);

            return null;
        });
    }

    public function read(): string
    {
        return DenariusLog::trace(__METHOD__, function (): string {
            $line = fgets($this->input);

            return is_string($line) ? rtrim($line, "\r\n") : '';
        });
    }

    public function hide(bool $hide): void
    {
        DenariusLog::trace(__METHOD__, function () use ($hide): mixed {
            if (!$this->interactive()) {
                return null;
            }
            ($this->command)($hide ? 'stty -echo' : 'stty echo');

            return null;
        });
    }

    private function interactive(): bool
    {
        return DenariusLog::trace(__METHOD__, function (): bool {
            return Optional::ofNullable($this->tty)
                ->map(fn (\Closure $tty): bool => (bool) $tty($this->input))
                ->orElse(stream_isatty($this->input));
        });
    }
}
