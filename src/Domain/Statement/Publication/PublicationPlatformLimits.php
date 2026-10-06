<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication;

/** Value object (constants) for kingdom publication bounds enforced platform-wide. */
final class PublicationPlatformLimits
{
    public const int MIN_EMBARGO_DAYS = 1;

    public const int MAX_EMBARGO_DAYS = 7;

    public const int DEFAULT_EMBARGO_DAYS = 3;

    public const int MIN_PAIR_WINDOW_DAYS = 7;

    public const int MAX_PAIR_WINDOW_DAYS = 21;

    public const int DEFAULT_PAIR_WINDOW_DAYS = 14;

    private function __construct()
    {
    }
}
