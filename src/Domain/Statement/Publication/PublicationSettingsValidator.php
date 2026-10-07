<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication;

use Amtgard\Denarius\Domain\Statement\Presentation\DisplayMode;
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

    public function canonicalDisplayMode(DisplayMode $mode): DisplayMode
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($method, $mode): DisplayMode {
            if ($mode === DisplayMode::All) {
                DenariusLog::debugBranch('display_mode_legacy_all', $method, ['requested' => $mode->value]);

                return DisplayMode::LessRedacted;
            }

            return $mode;
        });
    }
}
