<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Log;

/** Value object: branch log severity for decision-path lines. */
enum BranchLogLevel: string
{
    case Debug = 'debug';
    case Info = 'info';
    case Warn = 'warn';
}
