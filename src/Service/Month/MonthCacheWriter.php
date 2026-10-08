<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Month;

use Amtgard\Denarius\Domain\Statement\MonthStatement;
use Amtgard\Denarius\Domain\Statement\MonthWindow;
use Amtgard\Denarius\Domain\Statement\Presentation\DisplayMode;
use Amtgard\Denarius\Domain\Taxonomy\TaxonomyCatalog;
use Amtgard\Denarius\Utilities\Queue\KeyValue\KeyValueStore;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Facade: persist public month statements in Redis without TTL. */
final class MonthCacheWriter
{
    /** @var list<DisplayMode> */
    public const WARM_MODES = [
        DisplayMode::Summarized,
        DisplayMode::Redacted,
        DisplayMode::LessRedacted,
    ];

    public function __construct(
        private readonly KeyValueStore $store,
        private readonly TaxonomyCatalog $catalog,
        private readonly MonthCacheKeys $keys = new MonthCacheKeys(),
        private readonly MonthStatementCacheCodec $codec = new MonthStatementCacheCodec(),
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function write(int $kingdomId, DisplayMode $mode, MonthWindow $month, MonthStatement $statement): void
    {
        DenariusLog::trace(__METHOD__, function () use ($kingdomId, $mode, $month, $statement): mixed {
            $key = $this->statementKey($kingdomId, $mode, $month);
            $this->store->setPersistent($key, $this->codec->encode($statement));

            return null;
        });
    }

    public function deleteMonth(int $kingdomId, MonthWindow $month): void
    {
        DenariusLog::trace(__METHOD__, function () use ($kingdomId, $month): mixed {
            foreach (self::WARM_MODES as $mode) {
                $this->store->delete($this->statementKey($kingdomId, $mode, $month));
            }

            return null;
        });
    }

    public function statementKey(int $kingdomId, DisplayMode $mode, MonthWindow $month): string
    {
        return DenariusLog::trace(__METHOD__, function () use ($kingdomId, $mode, $month): string {
            return $this->keys->statement(
                $kingdomId,
                $mode->value,
                $month->key(),
                $this->catalog->taxonomyVersion(),
            );
        });
    }
}
