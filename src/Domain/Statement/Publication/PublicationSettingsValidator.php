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

    public function clampAmountQuantumCents(int $requested): int
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($method, $requested): int {
            $clamped = max(
                PublicationPlatformLimits::MIN_AMOUNT_QUANTUM_CENTS,
                min(PublicationPlatformLimits::MAX_AMOUNT_QUANTUM_CENTS, $requested),
            );
            if ($clamped !== $requested) {
                DenariusLog::debugBranch('amount_quantum_clamped', $method, [
                    'requested' => $requested,
                    'applied' => $clamped,
                ]);
            }

            return $clamped;
        });
    }

    /**
     * @return array{floor: int, ceiling: int, step: int}
     */
    public function clampBalanceQuantumSettings(int $floorCents, int $ceilingCents, int $stepCents): array
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($method, $floorCents, $ceilingCents, $stepCents): array {
            $floor = max(
                PublicationPlatformLimits::MIN_BALANCE_QUANTUM_FLOOR_CENTS,
                min(PublicationPlatformLimits::MAX_BALANCE_QUANTUM_FLOOR_CENTS, $floorCents),
            );
            $ceiling = max(
                PublicationPlatformLimits::MIN_BALANCE_QUANTUM_CEILING_CENTS,
                min(PublicationPlatformLimits::MAX_BALANCE_QUANTUM_CEILING_CENTS, $ceilingCents),
            );
            if ($ceiling < $floor) {
                DenariusLog::debugBranch('balance_quantum_ceiling_raised', $method, [
                    'requested_ceiling' => $ceilingCents,
                    'applied_ceiling' => $floor,
                    'floor' => $floor,
                ]);
                $ceiling = $floor;
            }
            $step = max(
                PublicationPlatformLimits::MIN_BALANCE_QUANTUM_STEP_CENTS,
                min(PublicationPlatformLimits::MAX_BALANCE_QUANTUM_STEP_CENTS, $stepCents),
            );
            if ($floor !== $floorCents || $ceiling !== $ceilingCents || $step !== $stepCents) {
                DenariusLog::debugBranch('balance_quantum_clamped', $method, [
                    'requested_floor' => $floorCents,
                    'requested_ceiling' => $ceilingCents,
                    'requested_step' => $stepCents,
                    'applied_floor' => $floor,
                    'applied_ceiling' => $ceiling,
                    'applied_step' => $step,
                ]);
            }

            return ['floor' => $floor, 'ceiling' => $ceiling, 'step' => $step];
        });
    }

    public function clampSummarizedCategoryMinLines(int $requested): int
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($method, $requested): int {
            $clamped = max(
                PublicationPlatformLimits::MIN_SUMMARIZED_CATEGORY_MIN_LINES,
                min(PublicationPlatformLimits::MAX_SUMMARIZED_CATEGORY_MIN_LINES, $requested),
            );
            if ($clamped !== $requested) {
                DenariusLog::debugBranch('summarized_category_min_lines_clamped', $method, [
                    'requested' => $requested,
                    'applied' => $clamped,
                ]);
            }

            return $clamped;
        });
    }
}
