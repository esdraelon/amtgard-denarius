<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication\Pattern;

/** Value object (constants): stable SOFT pattern ids applied in the pipeline registry. */
final class PublicationSoftPatternIds
{
    public const string PROFESSIONAL_SERVICES_KEYWORD = 'soft_professional_services_v1';

    private function __construct()
    {
    }
}
