<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy;

/** Value object: disclosure sensitivity for a taxonomy category slug. */
enum CategorySensitivity: string
{
    case Soft = 'soft';
}
