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

    public const int MIN_AMOUNT_QUANTUM_CENTS = 100;

    public const int MAX_AMOUNT_QUANTUM_CENTS = 5000;

    public const int DEFAULT_AMOUNT_QUANTUM_CENTS = 100;

    public const int MIN_BALANCE_QUANTUM_FLOOR_CENTS = 500;

    public const int MAX_BALANCE_QUANTUM_FLOOR_CENTS = 5000;

    public const int DEFAULT_BALANCE_QUANTUM_FLOOR_CENTS = 500;

    public const int MIN_BALANCE_QUANTUM_CEILING_CENTS = 500;

    public const int MAX_BALANCE_QUANTUM_CEILING_CENTS = 50000;

    public const int DEFAULT_BALANCE_QUANTUM_CEILING_CENTS = 500;

    public const int MIN_BALANCE_QUANTUM_STEP_CENTS = 0;

    public const int MAX_BALANCE_QUANTUM_STEP_CENTS = 2500;

    public const int DEFAULT_BALANCE_QUANTUM_STEP_CENTS = 0;

    private function __construct()
    {
    }
}
