<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Taxonomy;

/** Value object helper: manager-facing category picker label (flow + taxonomy label). */
final class TaxonomyCategoryDisplay
{
    public static function format(TransactionFlow $flow, string $label): string
    {
        return ucfirst($flow->value) . ': ' . $label;
    }
}
