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

    public function statement(int $kingdomId, int $generation, string $mode, string $month, string $taxonomyVersion): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdomId, $generation, $mode, $month, $taxonomyVersion): string {
            return sprintf('denarius:month:%d:%d:%s:%s:%s', $kingdomId, $generation, $mode, $month, $taxonomyVersion);
        });
    }

    public function taxonomyNamespace(): string
    {
        return DenariusLog::trace(__METHOD__, function (): string {
            return 'taxonomy_version';
        });
    }
}
