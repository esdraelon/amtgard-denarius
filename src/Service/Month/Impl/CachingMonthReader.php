<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Month\Impl;

use Amtgard\Denarius\Domain\Statement\MonthStatement;
use Amtgard\Denarius\Domain\Statement\MonthWindow;
use Amtgard\Denarius\Domain\Statement\Presentation\DisplayMode;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Service\Month\MonthCacheWriter;
use Amtgard\Denarius\Service\Month\MonthReader;
use Amtgard\Denarius\Service\Month\MonthStatementCacheCodec;
use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Amtgard\Denarius\Utilities\Queue\KeyValue\KeyValueStore;

/** Decorator: serve month statements from the persistent warm cache, filling misses from the origin. */
final class CachingMonthReader implements MonthReader
{
    public function __construct(
        private readonly MonthReader $origin,
        private readonly KeyValueStore $store,
        private readonly MonthCacheWriter $writer,
        private readonly MonthStatementCacheCodec $codec = new MonthStatementCacheCodec(),
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function statement(KingdomRecord $kingdom, MonthWindow $month): MonthStatement
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdom, $month): MonthStatement {
            $kingdomId = (int) $kingdom->getId();
            $mode = DisplayMode::fromStored($kingdom->getDisplayMode());
            $key = $this->writer->statementKey($kingdomId, $mode, $month);
            $cached = $this->codec->decode($this->store->get($key), $mode, $month);
            if ($cached !== null) {
                DenariusLog::debugBranch('month_cache_hit', __METHOD__, ['kingdomId' => $kingdomId, 'month' => $month->key()]);

                return $cached;
            }

            DenariusLog::debugBranch('month_cache_miss', __METHOD__, ['kingdomId' => $kingdomId, 'month' => $month->key()]);
            $statement = $this->origin->statement($kingdom, $month);
            $this->writer->write($kingdomId, $mode, $month, $statement);

            return $statement;
        });
    }
}
