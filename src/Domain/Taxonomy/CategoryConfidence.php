<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy;

/** Value object: confidence band thresholds for categorization (stored per row in M-TAX-03). */
final class CategoryConfidence
{
    public const AUTO_ACCEPT = 70;

    private function __construct()
    {
    }
}
