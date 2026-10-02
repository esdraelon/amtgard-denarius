<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Month;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class MonthCacheKeys
{
    public function generation(int $kingdomId): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdomId): string {
            return 'denarius:month-gen:' . $kingdomId;
        });
    }

    public function statement(int $kingdomId, int $generation, string $mode, string $month): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdomId, $generation, $mode, $month): string {
            return sprintf('denarius:month:%d:%d:%s:%s', $kingdomId, $generation, $mode, $month);
        });
    }
}
