<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Setup;

final class HiddenLine
{
    public function __construct(private readonly TextIo $io)
    {
    }

    public function read(string $label, bool $hidden): string
    {
        $this->io->write($label . ': ');
        if ($hidden) {
            $this->io->hide(true);
        }
        $line = trim($this->io->read());
        if ($hidden) {
            $this->io->hide(false);
            $this->io->write("\n");
        }

        return $line;
    }
}
