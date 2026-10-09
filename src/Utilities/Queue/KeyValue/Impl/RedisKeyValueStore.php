<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Queue\KeyValue\Impl;

use Amtgard\Denarius\Utilities\Log\DenariusLog;
use Amtgard\Denarius\Utilities\Queue\KeyValue\KeyValueStore;

final class RedisKeyValueStore implements KeyValueStore
{
    public function __construct(
        private readonly object $redis,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function get(string $key): ?string
    {
        return DenariusLog::trace(__METHOD__, function () use ($key): ?string {
            $value = $this->redis->get($key);
            if ($value === false || $value === null) {
                return null;
            }

            return (string) $value;
        });
    }

    public function set(string $key, string $value, int $ttlSeconds): void
    {
        DenariusLog::trace(__METHOD__, function () use ($key, $value, $ttlSeconds): mixed {
            $this->redis->setex($key, $ttlSeconds, $value);

            return null;
        });
    }

    public function setPersistent(string $key, string $value): void
    {
        DenariusLog::trace(__METHOD__, function () use ($key, $value): mixed {
            $this->redis->set($key, $value);

            return null;
        });
    }

    public function delete(string $key): void
    {
        DenariusLog::trace(__METHOD__, function () use ($key): mixed {
            $this->redis->del($key);

            return null;
        });
    }
}
