<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Setup\Io\Impl;

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
    }

    public function write(string $text): void
    {
        fwrite($this->output, $text);
    }

    public function read(): string
    {
        $line = fgets($this->input);

        return is_string($line) ? rtrim($line, "\r\n") : '';
    }

    public function hide(bool $hide): void
    {
        if (!$this->interactive()) {
            return;
        }
        ($this->command)($hide ? 'stty -echo' : 'stty echo');
    }

    private function interactive(): bool
    {
        return Optional::ofNullable($this->tty)
            ->map(fn (\Closure $tty): bool => (bool) $tty($this->input))
            ->orElse(stream_isatty($this->input));
    }
}
