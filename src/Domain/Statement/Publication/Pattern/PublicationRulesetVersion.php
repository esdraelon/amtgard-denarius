<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication\Pattern;

/** Value object (constant): active SOFT/HARD ruleset version for pipeline reprocessing. */
final class PublicationRulesetVersion
{
    public const int CURRENT = 1;

    private function __construct()
    {
    }
}
