<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Setup;

interface TextIo
{
    public function write(string $text): void;

    public function read(): string;

    public function hide(bool $hide): void;
}
