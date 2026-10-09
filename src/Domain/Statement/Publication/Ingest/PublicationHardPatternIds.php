<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication\Ingest;

/** Value object (constants): stable HARD pattern ids at ingest. */
final class PublicationHardPatternIds
{
    public const string VERIFY_KEYWORD = 'verify_keyword_v1';

    public const string MICRO_DEPOSIT_PAIR = 'micro_deposit_pair_v1';

    private function __construct()
    {
    }
}
