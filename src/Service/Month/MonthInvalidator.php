<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Month;

use Amtgard\Denarius\Utilities\Queue\KeyValue\KeyValueStore;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class MonthInvalidator
{
    public function __construct(
        private readonly KeyValueStore $store,
        private readonly MonthCacheKeys $keys = new MonthCacheKeys(),
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function forget(int $kingdomId): void
    {
        DenariusLog::trace(__METHOD__, function () use ($kingdomId): mixed {
            $key = $this->keys->generation($kingdomId);
            $next = ((int) ($this->store->get($key) ?? '0')) + 1;
            $this->store->set($key, (string) $next, 86400 * 30);

            return null;
        });
    }
}
