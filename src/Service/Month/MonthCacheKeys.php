<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Month;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Value object: Redis key names for persisted month statements. */
final class MonthCacheKeys
{
    public function statement(int $kingdomId, string $mode, string $month, string $taxonomyVersion): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdomId, $mode, $month, $taxonomyVersion): string {
            return sprintf('denarius:month:%d:%s:%s:%s', $kingdomId, $mode, $month, $taxonomyVersion);
        });
    }

    public function taxonomyNamespace(): string
    {
        return DenariusLog::trace(__METHOD__, function (): string {
            return 'taxonomy_version';
        });
    }
}
