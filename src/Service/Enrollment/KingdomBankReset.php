<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Enrollment;

/** Clears persisted bank link data for one kingdom so connect onboarding can run again. */
interface KingdomBankReset
{
    public function clearKingdom(int $kingdomId): void;
}
