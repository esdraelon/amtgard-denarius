<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Month;

use Amtgard\Denarius\Utilities\Queue\KeyValue\KeyValueStore;

final class MonthInvalidator
{
    public function __construct(
        private readonly KeyValueStore $store,
        private readonly MonthCacheKeys $keys = new MonthCacheKeys(),
    ) {
    }

    public function forget(int $kingdomId): void
    {
        $key = $this->keys->generation($kingdomId);
        $next = ((int) ($this->store->get($key) ?? '0')) + 1;
        $this->store->set($key, (string) $next, 86400 * 30);
    }
}
