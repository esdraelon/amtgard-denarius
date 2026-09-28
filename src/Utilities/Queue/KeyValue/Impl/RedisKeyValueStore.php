<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Utilities\Queue\KeyValue\Impl;

use Amtgard\Denarius\Utilities\Queue\KeyValue\KeyValueStore;

final class RedisKeyValueStore implements KeyValueStore
{
    public function __construct(
        private readonly object $redis,
    ) {
    }

    public function get(string $key): ?string
    {
        $value = $this->redis->get($key);
        if ($value === false || $value === null) {
            return null;
        }

        return (string) $value;
    }

    public function set(string $key, string $value, int $ttlSeconds): void
    {
        $this->redis->setex($key, $ttlSeconds, $value);
    }

    public function delete(string $key): void
    {
        $this->redis->del($key);
    }
}
