<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Setup\Io;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class HiddenLine
{
    public function __construct(private readonly TextIo $io)
    {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function read(string $label, bool $hidden): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($label, $hidden): string {
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
        });
    }
}
