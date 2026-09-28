<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Month;

final class MonthCacheKeys
{
    public function generation(int $kingdomId): string
    {
        return 'denarius:month-gen:' . $kingdomId;
    }

    public function statement(int $kingdomId, int $generation, string $mode, string $month): string
    {
        return sprintf('denarius:month:%d:%d:%s:%s', $kingdomId, $generation, $mode, $month);
    }
}
