<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Strategy: validates and clamps kingdom publication settings to platform limits. */
final class PublicationSettingsValidator
{
    public function clampEmbargoDays(int $requested): int
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($method, $requested): int {
            $clamped = max(PublicationPlatformLimits::MIN_EMBARGO_DAYS, min(PublicationPlatformLimits::MAX_EMBARGO_DAYS, $requested));
            if ($clamped !== $requested) {
                DenariusLog::debugBranch('embargo_days_clamped', $method, [
                    'requested' => $requested,
                    'applied' => $clamped,
                ]);
            }

            return $clamped;
        });
    }
}
